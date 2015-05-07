<?php
/**
 * Part of the Joomla! Framework Form Package
 *
 * @copyright  Copyright (C) 2005 - 2015 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Form;

use Joomla\Filter;
use Joomla\Uri\Uri;
use Joomla\Language\Language;
use Joomla\Language\Text;
use Joomla\Filesystem\Path;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

/**
 * Form Class for the Joomla! Framework.
 *
 * This class implements a robust API for constructing, populating, filtering, and validating forms.
 * It uses XML definitions to construct form fields and a variety of field and rule classes to render and validate the form.
 *
 * @link   http://www.w3.org/TR/html4/interact/forms.html
 * @link   http://www.w3.org/TR/html5/forms.html
 * @since  1.0
 */
class Form
{
	/**
	 * The Registry data store for form fields during display.
	 *
	 * @var    Registry
	 * @since  1.0
	 */
	protected $data;

	/**
	 * The form object errors array.
	 *
	 * @var    array
	 * @since  1.0
	 */
	protected $errors = array();

	/**
	 * The name of the form instance.
	 *
	 * @var    string
	 * @since  1.0
	 */
	protected $name;

	/**
	 * The form object options for use in rendering and validation.
	 *
	 * @var    array
	 * @since  1.0
	 */
	protected $options = array();

	/**
	 * Container for the Text object
	 *
	 * @var    Text
	 * @since  __DEPLOY_VERSION__
	 */
	private $text;

	/**
	 * The form XML definition.
	 *
	 * @var    \SimpleXMLElement
	 * @since  1.0
	 */
	protected $xml;

	/**
	 * Form instances.
	 *
	 * @var    Form[]
	 * @since  1.0
	 */
	protected static $forms = array();

	/**
	 * Method to instantiate the form object.
	 *
	 * @param   string  $name     The name of the form.
	 * @param   array   $options  An array of form options.
	 *
	 * @since   1.0
	 */
	public function __construct($name, array $options = array())
	{
		// Set the name for the form.
		$this->name = $name;

		// Initialise the Registry data.
		$this->data = new Registry;

		// Set the options if specified.
		$this->options['control'] = isset($options['control']) ? $options['control'] : false;
	}

	/**
	 * Method to bind data to the form.
	 *
	 * @param   mixed  $data  An array or object of data to bind to the form.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 */
	public function bind($data)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		// The data must be an object or array.
		if (!is_object($data) && !is_array($data))
		{
			return false;
		}

		// Convert the object to an array.
		if ($data instanceof Registry)
		{
			$data = $data->toArray();
		}
		elseif (is_object($data))
		{
			$data = (array) $data;
		}

		// Process the input data.
		foreach ($data as $k => $v)
		{
			if ($this->findField($k))
			{
				// If the field exists set the value.
				$this->data->set($k, $v);
			}
			elseif (is_object($v) || ArrayHelper::isAssociative($v))
			{
				// If the value is an object or an associative array hand it off to the recursive bind level method.
				$this->bindLevel($k, $v);
			}
		}

		return true;
	}

	/**
	 * Method to bind data to the form for the group level.
	 *
	 * @param   string  $group  The dot-separated form group path on which to bind the data.
	 * @param   mixed   $data   An array or object of data to bind to the form for the group level.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected function bindLevel($group, $data)
	{
		// Ensure the input data is an array.
		settype($data, 'array');

		// Process the input data.
		foreach ($data as $k => $v)
		{
			if ($this->findField($k, $group))
			{
				// If the field exists set the value.
				$this->data->set($group . '.' . $k, $v);
			}
			elseif (is_object($v) || ArrayHelper::isAssociative($v))
			{
				// If the value is an object or an associative array, hand it off to the recursive bind level method
				$this->bindLevel($group . '.' . $k, $v);
			}
		}
	}

	/**
	 * Method to filter the form data.
	 *
	 * @param   array   $data   An array of field values to filter.
	 * @param   string  $group  The dot-separated form group path on which to filter the fields.
	 *
	 * @return  mixed  Array or false.
	 *
	 * @since   1.0
	 */
	public function filter($data, $group = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		$input = new Registry($data);
		$output = new Registry;

		// Get the fields for which to filter the data.
		$fields = $this->findFieldsByGroup($group);

		if (!$fields)
		{
			// PANIC!
			return false;
		}

		// Filter the fields.
		foreach ($fields as $field)
		{
			$name = (string) $field['name'];

			// Get the field groups for the element.
			$attrs = $field->xpath('ancestor::fields[@name]/@name');
			$groups = array_map('strval', $attrs ? $attrs : array());
			$group = implode('.', $groups);

			// Get the field value from the data input.
			if ($group)
			{
				// Filter the value if it exists.
				if ($input->exists($group . '.' . $name))
				{
					$output->set($group . '.' . $name, $this->filterField($field, $input->get($group . '.' . $name, (string) $field['default'])));
				}
			}
			else
			{
				// Filter the value if it exists.
				if ($input->exists($name))
				{
					$output->set($name, $this->filterField($field, $input->get($name, (string) $field['default'])));
				}
			}
		}

		return $output->toArray();
	}

	/**
	 * Return all errors, if any.
	 *
	 * @return  array  Array of error messages or RuntimeException objects.
	 *
	 * @since   1.0
	 */
	public function getErrors()
	{
		return $this->errors;
	}

	/**
	 * Method to get a form field represented as a Field object.
	 *
	 * @param   string  $name   The name of the form field.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 * @param   mixed   $value  The optional value to use as the default for the field.
	 *
	 * @return  Field|boolean  The Field object for the field or boolean false on error.
	 *
	 * @since   1.0
	 */
	public function getField($name, $group = null, $value = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		// Attempt to find the field by name and group.
		$element = $this->findField($name, $group);

		// If the field element was not found return false.
		if (!$element)
		{
			return false;
		}

		return $this->loadField($element, $group, $value);
	}

	/**
	 * Method to get an attribute value from a field XML element.  If the attribute doesn't exist or
	 * is null then the optional default value will be used.
	 *
	 * @param   string  $name       The name of the form field for which to get the attribute value.
	 * @param   string  $attribute  The name of the attribute for which to get a value.
	 * @param   mixed   $default    The optional default value to use if no attribute value exists.
	 * @param   string  $group      The optional dot-separated form group path on which to find the field.
	 *
	 * @return  mixed  The attribute value for the field.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function getFieldAttribute($name, $attribute, $default = null, $group = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::getFieldAttribute `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Find the form field element from the definition.
		$element = $this->findField($name, $group);

		// If the element exists and the attribute exists for the field return the attribute value.
		if (($element instanceof \SimpleXMLElement) && ((string) $element[$attribute]))
		{
			return (string) $element[$attribute];
		}
		else
		// Otherwise return the given default value.
		{
			return $default;
		}
	}

	/**
	 * Method to get an array of Field objects in a given fieldset by name.  If no name is given then all fields are returned.
	 *
	 * @param   string  $set  The optional name of the fieldset.
	 *
	 * @return  Field[]  An array of Field objects in the fieldset.
	 *
	 * @since   1.0
	 */
	public function getFieldset($set = null)
	{
		$fields = array();

		// Get all of the field elements in the fieldset.
		if ($set)
		{
			$elements = $this->findFieldsByFieldset($set);
		}
		else
		// Get all fields.
		{
			$elements = $this->findFieldsByGroup();
		}

		// If no field elements were found return empty.
		if (empty($elements))
		{
			return $fields;
		}

		// Build the result array from the found field elements.
		/** @var \SimpleXMLElement $element */
		foreach ($elements as $element)
		{
			// Get the field groups for the element.
			$attrs = $element->xpath('ancestor::fields[@name]/@name');
			$groups = array_map('strval', $attrs ? $attrs : array());
			$group = implode('.', $groups);

			// If the field is successfully loaded add it to the result array.
			if ($field = $this->loadField($element, $group))
			{
				$fields[$field->id] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Method to get an array of fieldset objects optionally filtered over a given field group.
	 *
	 * @param   string  $group  The dot-separated form group path on which to filter the fieldsets.
	 *
	 * @return  array  The array of fieldset objects.
	 *
	 * @since   1.0
	 */
	public function getFieldsets($group = null)
	{
		$fieldsets = array();
		$sets = array();

		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return $fieldsets;
		}

		if ($group)
		{
			// Get the fields elements for a given group.
			$elements = &$this->findGroup($group);

			foreach ($elements as &$element)
			{
				// Get an array of <fieldset /> elements and fieldset attributes within the fields element.
				if ($tmp = $element->xpath('descendant::fieldset[@name] | descendant::field[@fieldset]/@fieldset'))
				{
					$sets = array_merge($sets, (array) $tmp);
				}
			}
		}
		else
		{
			// Get an array of <fieldset /> elements and fieldset attributes.
			$sets = $this->xml->xpath('//fieldset[@name] | //field[@fieldset]/@fieldset');
		}

		// If no fieldsets are found return empty.
		if (empty($sets))
		{
			return $fieldsets;
		}

		// Process each found fieldset.
		foreach ($sets as $set)
		{
			// Are we dealing with a fieldset element?
			if ((string) $set['name'])
			{
				// Only create it if it doesn't already exist.
				if (empty($fieldsets[(string) $set['name']]))
				{
					// Build the fieldset object.
					$fieldset = (object) array('name' => '', 'label' => '', 'description' => '');

					foreach ($set->attributes() as $name => $value)
					{
						$fieldset->$name = (string) $value;
					}

					// Add the fieldset object to the list.
					$fieldsets[$fieldset->name] = $fieldset;
				}
			}
			else
			// Must be dealing with a fieldset attribute.
			{
				// Only create it if it doesn't already exist.
				if (empty($fieldsets[(string) $set]))
				{
					// Attempt to get the fieldset element for data (throughout the entire form document).
					$tmp = $this->xml->xpath('//fieldset[@name="' . (string) $set . '"]');

					// If no element was found, build a very simple fieldset object.
					if (empty($tmp))
					{
						$fieldset = (object) array('name' => (string) $set, 'label' => '', 'description' => '');
					}
					else
					// Build the fieldset object from the element.
					{
						$fieldset = (object) array('name' => '', 'label' => '', 'description' => '');

						foreach ($tmp[0]->attributes() as $name => $value)
						{
							$fieldset->$name = (string) $value;
						}
					}

					// Add the fieldset object to the list.
					$fieldsets[$fieldset->name] = $fieldset;
				}
			}
		}

		return $fieldsets;
	}

	/**
	 * Retrieves the Text object
	 *
	 * @return  Text
	 *
	 * @since   __DEPLOY_VERSION__
	 * @throws  \RuntimeException
	 */
	public function getText()
	{
		if (!($this->text instanceof Text))
		{
			throw new \RuntimeException('A Joomla\\Language\\Text object is not set.');
		}

		return $this->text;
	}

	/**
	 * Method to set the form control
	 *
	 * This string serves as a container for all form fields. For example, if there is a field named 'foo'
	 * and a field named 'bar' and the form control is empty the fields will be rendered like:
	 * <input name="foo" /> and <input name="bar" />.  If the form control is set to 'joomla' however, the fields
	 * would be rendered like: <input name="joomla[foo]" /> and <input name="joomla[bar]" />.
	 *
	 * @param   string  $control  The form control string.
	 *
	 * @return  Form
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function setFormControl($control)
	{
		$this->options['control'] = (string) $control;

		return $this;
	}

	/**
	 * Method to get the form control.
	 *
	 * This string serves as a container for all form fields. For example, if there is a field named 'foo'
	 * and a field named 'bar' and the form control is empty the fields will be rendered like:
	 * <input name="foo" /> and <input name="bar" />.  If the form control is set to 'joomla' however, the fields
	 * would be rendered like: <input name="joomla[foo]" /> and <input name="joomla[bar]" />.
	 *
	 * @return  string  The form control string.
	 *
	 * @since   1.0
	 */
	public function getFormControl()
	{
		return (string) $this->options['control'];
	}

	/**
	 * Method to get an array of Field objects in a given field group by name.
	 *
	 * @param   string   $group   The dot-separated form group path for which to get the form fields.
	 * @param   boolean  $nested  True to also include fields in nested groups that are inside of the
	 *                            group for which to find fields.
	 *
	 * @return  Field[]  The array of Field objects in the field group.
	 *
	 * @since   1.0
	 */
	public function getGroup($group, $nested = false)
	{
		$fields = array();

		// Get all of the field elements in the field group.
		$elements = $this->findFieldsByGroup($group, $nested);

		// If no field elements were found return empty.
		if (empty($elements))
		{
			return $fields;
		}

		// Build the result array from the found field elements.
		/** @var \SimpleXMLElement $element */
		foreach ($elements as $element)
		{
			// Get the field groups for the element.
			$attrs	= $element->xpath('ancestor::fields[@name]/@name');
			$groups	= array_map('strval', $attrs ? $attrs : array());
			$group	= implode('.', $groups);

			// If the field is successfully loaded add it to the result array.
			if ($field = $this->loadField($element, $group))
			{
				$fields[$field->id] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Method to get a form field markup for the field input.
	 *
	 * @param   string  $name   The name of the form field.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 * @param   mixed   $value  The optional value to use as the default for the field.
	 *
	 * @return  string  The form field markup.
	 *
	 * @since   1.0
	 */
	public function getInput($name, $group = null, $value = null)
	{
		// Attempt to get the form field.
		if ($field = $this->getField($name, $group, $value))
		{
			// Try to inject the text object into the field
			try
			{
				$field->setText($this->getText());
			}
			catch (\RuntimeException $exception)
			{
				// A Text object was not set, ignore the error and try to continue processing
			}

			return $field->input;
		}

		return '';
	}

	/**
	 * Method to get the label for a field input.
	 *
	 * @param   string  $name   The name of the form field.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 *
	 * @return  string  The form field label.
	 *
	 * @since   1.0
	 */
	public function getLabel($name, $group = null)
	{
		// Attempt to get the form field.
		if ($field = $this->getField($name, $group))
		{
			return $field->label;
		}

		return '';
	}

	/**
	 * Method to get the form name.
	 *
	 * @return  string  The name of the form.
	 *
	 * @since   1.0
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Method to get the value of a field.
	 *
	 * @param   string  $name     The name of the field for which to get the value.
	 * @param   string  $group    The optional dot-separated form group path on which to get the value.
	 * @param   mixed   $default  The optional default value of the field value is empty.
	 *
	 * @return  mixed  The value of the field or the default value if empty.
	 *
	 * @since   1.0
	 */
	public function getValue($name, $group = null, $default = null)
	{
		// If a group is set use it.
		if ($group)
		{
			$return = $this->data->get($group . '.' . $name, $default);
		}
		else
		{
			$return = $this->data->get($name, $default);
		}

		return $return;
	}

	/**
	 * Method to load the form description from an XML string or object.
	 *
	 * The replace option works per field.  If a field being loaded already exists in the current
	 * form definition then the behavior or load will vary depending upon the replace flag.  If it
	 * is set to true, then the existing field will be replaced in its exact location by the new
	 * field being loaded.  If it is false, then the new field being loaded will be ignored and the
	 * method will move on to the next field to load.
	 *
	 * @param   string          $data     The name of an XML string or object.
	 * @param   boolean         $replace  Flag to toggle whether form fields should be replaced if a field
	 *                                    already exists with the same group/name.
	 * @param   boolean|string  $xpath    An optional xpath to search for the fields.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   1.0
	 */
	public function load($data, $replace = true, $xpath = false)
	{
		// If the data to load isn't already an XML element or string return false.
		if ((!($data instanceof \SimpleXMLElement)) && (!is_string($data)))
		{
			return false;
		}

		// Attempt to load the XML if a string.
		if (is_string($data))
		{
			try
			{
				$data = new \SimpleXMLElement($data);
			}
			catch (\Exception $e)
			{
				return false;
			}

			// Make sure the XML loaded correctly.
			if (!$data)
			{
				return false;
			}
		}

		// If we have no XML definition at this point let's make sure we get one.
		if (empty($this->xml))
		{
			// If no XPath query is set to search for fields, and we have a <form />, set it and return.
			if (!$xpath && ($data->getName() == 'form'))
			{
				$this->xml = $data;

				// Synchronize any paths found in the load.
				$this->syncPaths();

				return true;
			}
			else
			// Create a root element for the form.
			{
				$this->xml = new \SimpleXMLElement('<form></form>');
			}
		}

		// Get the XML elements to load.
		$elements = array();

		if ($xpath)
		{
			$elements = $data->xpath($xpath);
		}
		elseif ($data->getName() == 'form')
		{
			$elements = $data->children();
		}

		// If there is nothing to load return true.
		if (empty($elements))
		{
			return true;
		}

		// Load the found form elements.
		foreach ($elements as $element)
		{
			// Get an array of fields with the correct name.
			$fields = $element->xpath('descendant-or-self::field');

			foreach ($fields as $field)
			{
				// Get the group names as strings for ancestor fields elements.
				$attrs = $field->xpath('ancestor::fields[@name]/@name');
				$groups = array_map('strval', $attrs ? $attrs : array());

				// Check to see if the field exists in the current form.
				if ($current = $this->findField((string) $field['name'], implode('.', $groups)))
				{
					// If set to replace found fields, replace the data and remove the field so we don't add it twice.
					if ($replace)
					{
						$olddom = dom_import_simplexml($current);
						$loadeddom = dom_import_simplexml($field);
						$addeddom = $olddom->ownerDocument->importNode($loadeddom);
						$olddom->parentNode->replaceChild($addeddom, $olddom);
						$loadeddom->parentNode->removeChild($loadeddom);
					}
					else
					{
						unset($field);
					}
				}
			}

			// Merge the new field data into the existing XML document.
			static::addNode($this->xml, $element);
		}

		// Synchronize any paths found in the load.
		$this->syncPaths();

		return true;
	}

	/**
	 * Method to load the form description from an XML file.
	 *
	 * The reset option works on a group basis. If the XML file references
	 * groups that have already been created they will be replaced with the
	 * fields in the new XML file unless the $reset parameter has been set
	 * to false.
	 *
	 * @param   string          $file   The filesystem path of an XML file.
	 * @param   boolean         $reset  Flag to toggle whether form fields should be replaced if a field
	 *                                  already exists with the same group/name.
	 * @param   boolean|string  $xpath  An optional xpath to search for the fields.
	 *
	 * @return  boolean  True on success, false otherwise.
	 *
	 * @since   1.0
	 */
	public function loadFile($file, $reset = true, $xpath = false)
	{
		// Check to see if the path is an absolute path.
		if (!is_file($file))
		{
			// Not an absolute path so let's attempt to find one using JPath.
			$file = Path::find(FormHelper::addFormPath(), strtolower($file) . '.xml');

			// If unable to find the file return false.
			if (!$file)
			{
				return false;
			}
		}

		// Attempt to load the XML file.
		$xml = simplexml_load_file($file);

		return $this->load($xml, $reset, $xpath);
	}

	/**
	 * Method to remove a field from the form definition.
	 *
	 * @param   string  $name   The name of the form field for which remove.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function removeField($name, $group = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::removeField `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Find the form field element from the definition.
		$element = $this->findField($name, $group);

		// If the element exists remove it from the form definition.
		if ($element instanceof \SimpleXMLElement)
		{
			$dom = dom_import_simplexml($element);
			$dom->parentNode->removeChild($dom);
		}

		return true;
	}

	/**
	 * Method to remove a group from the form definition.
	 *
	 * @param   string  $group  The dot-separated form group path for the group to remove.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function removeGroup($group)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::removeGroup `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Get the fields elements for a given group.
		$elements = &$this->findGroup($group);

		foreach ($elements as &$element)
		{
			$dom = dom_import_simplexml($element);
			$dom->parentNode->removeChild($dom);
		}

		return true;
	}

	/**
	 * Method to reset the form data store and optionally the form XML definition.
	 *
	 * @param   boolean  $xml  True to also reset the XML form definition.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 */
	public function reset($xml = false)
	{
		unset($this->data);
		$this->data = new Registry;

		if ($xml)
		{
			unset($this->xml);
			$this->xml = new \SimpleXMLElement('<form></form>');
		}

		return true;
	}

	/**
	 * Method to set a field XML element to the form definition.  If the replace flag is set then
	 * the field will be set whether it already exists or not.  If it isn't set, then the field
	 * will not be replaced if it already exists.
	 *
	 * @param   \SimpleXMLElement  $element  The XML element object representation of the form field.
	 * @param   string             $group    The optional dot-separated form group path on which to set the field.
	 * @param   boolean            $replace  True to replace an existing field if one already exists.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function setField(\SimpleXMLElement $element, $group = null, $replace = true)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::setField `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Find the form field element from the definition.
		$old = $this->findField((string) $element['name'], $group);

		// If an existing field is found and replace flag is false do nothing and return true.
		if (!$replace && !empty($old))
		{
			return true;
		}

		// If an existing field is found and replace flag is true remove the old field.
		if ($replace && !empty($old) && ($old instanceof \SimpleXMLElement))
		{
			$dom = dom_import_simplexml($old);
			$dom->parentNode->removeChild($dom);
		}

		// If no existing field is found find a group element and add the field as a child of it.
		if ($group)
		{
			// Get the fields elements for a given group.
			$fields = &$this->findGroup($group);

			// If an appropriate fields element was found for the group, add the element.
			if (isset($fields[0]) && ($fields[0] instanceof \SimpleXMLElement))
			{
				static::addNode($fields[0], $element);
			}
		}
		else
		{
			// Set the new field to the form.
			static::addNode($this->xml, $element);
		}

		// Synchronize any paths found in the load.
		$this->syncPaths();

		return true;
	}

	/**
	 * Method to set an attribute value for a field XML element.
	 *
	 * @param   string  $name       The name of the form field for which to set the attribute value.
	 * @param   string  $attribute  The name of the attribute for which to set a value.
	 * @param   mixed   $value      The value to set for the attribute.
	 * @param   string  $group      The optional dot-separated form group path on which to find the field.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function setFieldAttribute($name, $attribute, $value, $group = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::setFieldAttribute `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Find the form field element from the definition.
		$element = $this->findField($name, $group);

		// If the element doesn't exist return false.
		if (!($element instanceof \SimpleXMLElement))
		{
			return false;
		}
		else
		// Otherwise set the attribute and return true.
		{
			$element[$attribute] = $value;

			// Synchronize any paths found in the load.
			$this->syncPaths();

			return true;
		}
	}

	/**
	 * Method to set some field XML elements to the form definition.  If the replace flag is set then
	 * the fields will be set whether they already exists or not.  If it isn't set, then the fields
	 * will not be replaced if they already exist.
	 *
	 * @param   array    &$elements  The array of XML element object representations of the form fields.
	 * @param   string   $group      The optional dot-separated form group path on which to set the fields.
	 * @param   boolean  $replace    True to replace existing fields if they already exist.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function setFields(&$elements, $group = null, $replace = true)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			throw new \UnexpectedValueException(sprintf('%s::setFields `xml` is not an instance of SimpleXMLElement', get_class($this)));
		}

		// Make sure the elements to set are valid.
		foreach ($elements as $element)
		{
			if (!($element instanceof \SimpleXMLElement))
			{
				throw new \UnexpectedValueException(sprintf('$element not SimpleXMLElement in %s::setFields', get_class($this)));
			}
		}

		// Set the fields.
		$return = true;

		foreach ($elements as $element)
		{
			if (!$this->setField($element, $group, $replace))
			{
				$return = false;
			}
		}

		// Synchronize any paths found in the load.
		$this->syncPaths();

		return $return;
	}

	/**
	 * Sets the Text object
	 *
	 * @param   Text  $text  The Text object to store
	 *
	 * @return  Form  Instance of this class.
	 *
	 * @since   __DEPLOY_VERSION__
	 * @throws  \RuntimeException
	 */
	public function setText(Text $text)
	{
		$this->text = $text;

		return $this;
	}

	/**
	 * Method to set the value of a field. If the field does not exist in the form then the method
	 * will return false.
	 *
	 * @param   string  $name   The name of the field for which to set the value.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 * @param   mixed   $value  The value to set for the field.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 */
	public function setValue($name, $group = null, $value = null)
	{
		// If the field does not exist return false.
		if (!$this->findField($name, $group))
		{
			return false;
		}

		// If a group is set use it.
		if ($group)
		{
			$this->data->set($group . '.' . $name, $value);
		}
		else
		{
			$this->data->set($name, $value);
		}

		return true;
	}

	/**
	 * Method to validate form data.
	 *
	 * Validation warnings will be pushed into Form::errors and should be
	 * retrieved with Form::getErrors() when validate returns boolean false.
	 *
	 * @param   array   $data   An array of field values to validate.
	 * @param   string  $group  The optional dot-separated form group path on which to filter the
	 *                          fields to be validated.
	 *
	 * @return  mixed  True on success.
	 *
	 * @since   1.0
	 */
	public function validate($data, $group = null)
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		$return = true;

		// Create an input registry object from the data to validate.
		$input = new Registry($data);

		// Get the fields for which to validate the data.
		$fields = $this->findFieldsByGroup($group);

		if (!$fields)
		{
			// PANIC!
			return false;
		}

		// Validate the fields.
		foreach ($fields as $field)
		{
			$value = null;
			$name = (string) $field['name'];

			// Get the group names as strings for ancestor fields elements.
			$attrs = $field->xpath('ancestor::fields[@name]/@name');
			$groups = array_map('strval', $attrs ? $attrs : array());
			$group = implode('.', $groups);

			// Get the value from the input data.
			if ($group)
			{
				$value = $input->get($group . '.' . $name);
			}
			else
			{
				$value = $input->get($name);
			}

			// Validate the field.
			$valid = $this->validateField($field, $group, $value, $input);

			// Check for an error.
			if ($valid instanceof \Exception)
			{
				array_push($this->errors, $valid);
				$return = false;
			}
		}

		return $return;
	}

	/**
	 * Method to apply an input filter to a value based on field data.
	 *
	 * @param   string  $element  The XML element object representation of the form field.
	 * @param   mixed   $value    The value to filter for the field.
	 *
	 * @return  mixed   The filtered value.
	 *
	 * @since   1.0
	 */
	protected function filterField($element, $value)
	{
		// Make sure there is a valid SimpleXMLElement.
		if (!($element instanceof \SimpleXMLElement))
		{
			return false;
		}

		// Get the field filter type.
		$filter = (string) $element['filter'];

		// Process the input value based on the filter.
		$return = null;

		switch (strtoupper($filter))
		{
			// Do nothing, thus leaving the return value as null.
			case 'UNSET':
				break;

			// No Filter.
			case 'RAW':
				$return = $value;
				break;

			// Filter the input as an array of integers.
			case 'INT_ARRAY':
				// Make sure the input is an array.
				if (is_object($value))
				{
					$value = get_object_vars($value);
				}

				$value = is_array($value) ? $value : array($value);

				$value = ArrayHelper::toInteger($value);
				$return = $value;
				break;

			// Filter safe HTML.
			case 'SAFEHTML':
				$filterInput = new Filter\InputFilter(null, null, 1, 1);
				$return = $filterInput->clean($value, 'string');
				break;

			// Ensures a protocol is present in the saved field. Only use when
			// the only permitted protocols requre '://'. See Rule\Url for list of these.

			case 'URL':
				if (empty($value))
				{
					return false;
				}

				$filterInput = new Filter\InputFilter;
				$value = $filterInput->clean($value, 'html');
				$value = trim($value);

				// Check for a protocol
				$protocol = parse_url($value, PHP_URL_SCHEME);

				// If there is no protocol and the relative option is not specified,
				// we assume that it is an external URL and prepend http://.
				if (($element['type'] == 'url' && !$protocol &&  !$element['relative'])
					|| (!$element['type'] == 'url' && !$protocol))
				{
					$protocol = 'http';

					// If it looks like an internal link, then add the root.
					if (substr($value, 0) == 'index.php')
					{
						$value = Uri::root() . $value;
					}

					// Otherwise we treat it is an external link.
					// Put the url back together.
					$value = $protocol . '://' . $value;
				}

				// If relative URLS are allowed we assume that URLs without protocols are internal.
				elseif (!$protocol && $element['relative'])
				{
					$host = Uri::getInstance('SERVER')->gethost();

					// If it starts with the host string, just prepend the protocol.
					if (substr($value, 0) == $host)
					{
						$value = 'http://' . $value;
					}
					else
					// Otherwise prepend the root.
					{
						$value = Uri::root() . $value;
					}
				}

				$return = $value;
				break;

			case 'TEL':
				$value = trim($value);

				// Does it match the NANP pattern?
				if (preg_match('/^(?:\+?1[-. ]?)?\(?([2-9][0-8][0-9])\)?[-. ]?([2-9][0-9]{2})[-. ]?([0-9]{4})$/', $value) == 1)
				{
					$number = (string) preg_replace('/[^\d]/', '', $value);

					if (substr($number, 0, 1) == 1)
					{
						$number = substr($number, 1);
					}

					$result = '1.' . $number;
				}
				elseif (preg_match('/^\+(?:[0-9] ?){6,14}[0-9]$/', $value) == 1)
				// If not, does it match ITU-T?
				{
					$countrycode = substr($value, 0, strpos($value, ' '));
					$countrycode = (string) preg_replace('/[^\d]/', '', $countrycode);
					$number = strstr($value, ' ');
					$number = (string) preg_replace('/[^\d]/', '', $number);
					$result = $countrycode . '.' . $number;
				}
				elseif (preg_match('/^\+[0-9]{1,3}\.[0-9]{4,14}(?:x.+)?$/', $value) == 1)
				// If not, does it match EPP?
				{
					if (strstr($value, 'x'))
					{
						$xpos = strpos($value, 'x');
						$value = substr($value, 0, $xpos);
					}

					$result = str_replace('+', '', $value);
				}
				elseif (preg_match('/[0-9]{1,3}\.[0-9]{4,14}$/', $value) == 1)
				// Maybe it is already ccc.nnnnnnn?
				{
					$result = $value;
				}
				else
				// If not, can we make it a string of digits?
				{
					$value = (string) preg_replace('/[^\d]/', '', $value);

					if ($value != null && strlen($value) <= 15)
					{
						$length = strlen($value);

						// If it is fewer than 13 digits assume it is a local number
						if ($length <= 12)
						{
							$result = '.' . $value;
						}
						else
						{
							// If it has 13 or more digits let's make a country code.
							$cclen = $length - 12;
							$result = substr($value, 0, $cclen) . '.' . substr($value, $cclen);
						}
					}
					else
					// If not let's not save anything.
					{
						$result = '';
					}
				}

				$return = $result;

				break;

			default:
				// Check for a callback filter.
				if (strpos($filter, '::') !== false && is_callable(explode('::', $filter)))
				{
					$return = call_user_func(explode('::', $filter), $value);
				}
				elseif (function_exists($filter))
				// Filter using a callback function if specified.
				{
					$return = call_user_func($filter, $value);
				}
				else
				// Filter using InputFilter. All HTML code is filtered by default.
				{
					$filterInput = new Filter\InputFilter;
					$return = $filterInput->clean($value, $filter);
				}
				break;
		}

		return $return;
	}

	/**
	 * Method to get a form field represented as an XML element object.
	 *
	 * @param   string  $name   The name of the form field.
	 * @param   string  $group  The optional dot-separated form group path on which to find the field.
	 *
	 * @return  \SimpleXMLElement|boolean  Boolean false on error or a SimpleXMLElement object.
	 *
	 * @since   1.0
	 */
	protected function findField($name, $group = null)
	{
		$element = false;
		$fields = array();

		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		// Let's get the appropriate field element based on the method arguments.
		if ($group)
		{
			// Get the fields elements for a given group.
			$elements = &$this->findGroup($group);

			// Get all of the field elements with the correct name for the fields elements.
			/** @var \SimpleXMLElement $element */
			foreach ($elements as $element)
			{
				// If there are matching field elements add them to the fields array.
				if ($tmp = $element->xpath('descendant::field[@name="' . $name . '"]'))
				{
					$fields = array_merge($fields, $tmp);
				}
			}

			// Make sure something was found.
			if (empty($fields))
			{
				return false;
			}

			// Use the first correct match in the given group.
			$groupNames = explode('.', $group);

			/** @var \SimpleXMLElement $field */
			foreach ($fields as &$field)
			{
				// Get the group names as strings for ancestor fields elements.
				$attrs = $field->xpath('ancestor::fields[@name]/@name');
				$names = array_map('strval', $attrs ? $attrs : array());

				// If the field is in the exact group use it and break out of the loop.
				if ($names == (array) $groupNames)
				{
					$element = &$field;
					break;
				}
			}
		}
		else
		{
			// Get an array of fields with the correct name.
			$fields = $this->xml->xpath('//field[@name="' . $name . '"]');

			// Make sure something was found.
			if (empty($fields))
			{
				return false;
			}

			// Search through the fields for the right one.
			foreach ($fields as &$field)
			{
				// If we find an ancestor fields element with a group name then it isn't what we want.
				if ($field->xpath('ancestor::fields[@name]'))
				{
					continue;
				}
				else
				// Found it!
				{
					$element = &$field;
					break;
				}
			}
		}

		return $element;
	}

	/**
	 * Method to get an array of <field /> elements from the form XML document which are
	 * in a specified fieldset by name.
	 *
	 * @param   string  $name  The name of the fieldset.
	 *
	 * @return  \SimpleXMLElement[]|boolean  Boolean false on error or array of SimpleXMLElement objects.
	 *
	 * @since   1.0
	 */
	protected function &findFieldsByFieldset($name)
	{
		$false = false;

		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return $false;
		}

		/*
		 * Get an array of <field /> elements that are underneath a <fieldset /> element
		 * with the appropriate name attribute (unless they are the decendents
		 * of another <field /> element for some reaon), and also any <field /> elements
		 * with the appropriate fieldset attribute.
		 */
		$fields = $this->xml->xpath('//fieldset[@name="' . $name . '"]//field[not(ancestor::field)] | //field[@fieldset="' . $name . '"]');

		return $fields;
	}

	/**
	 * Method to get an array of <field /> elements from the form XML document which are
	 * in a control group by name.
	 *
	 * @param   mixed    $group   The optional dot-separated form group path on which to find the fields.
	 *                            Null will return all fields. False will return fields not in a group.
	 * @param   boolean  $nested  True to also include fields in nested groups that are inside of the
	 *                            group for which to find fields.
	 *
	 * @return  \SimpleXMLElement[]|boolean  Boolean false on error or array of SimpleXMLElement objects.
	 *
	 * @since   1.0
	 */
	protected function &findFieldsByGroup($group = null, $nested = false)
	{
		$false = false;
		$fields = array();

		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return $false;
		}

		// Get only fields in a specific group?
		if ($group)
		{
			// Get the fields elements for a given group.
			$elements = &$this->findGroup($group);

			// Get all of the field elements for the fields elements.
			/** @var \SimpleXMLElement $element */
			foreach ($elements as $element)
			{
				// If there are field elements add them to the return result.
				if ($tmp = $element->xpath('descendant::field'))
				{
					// If we also want fields in nested groups then just merge the arrays.
					if ($nested)
					{
						$fields = array_merge($fields, $tmp);
					}
					else
					// If we want to exclude nested groups then we need to check each field.
					{
						$groupNames = explode('.', $group);

						foreach ($tmp as $field)
						{
							// Get the names of the groups that the field is in.
							$attrs = $field->xpath('ancestor::fields[@name]/@name');
							$names = array_map('strval', $attrs ? $attrs : array());

							// If the field is in the specific group then add it to the return list.
							if ($names == (array) $groupNames)
							{
								$fields = array_merge($fields, array($field));
							}
						}
					}
				}
			}
		}
		elseif ($group === false)
		{
			// Get only field elements not in a group.
			$fields = $this->xml->xpath('descendant::fields[not(@name)]/field | descendant::fields[not(@name)]/fieldset/field ');
		}
		else
		{
			// Get an array of all the <field /> elements.
			$fields = $this->xml->xpath('//field');
		}

		return $fields;
	}

	/**
	 * Method to get a form field group represented as an XML element object.
	 *
	 * @param   string  $group  The dot-separated form group path on which to find the group.
	 *
	 * @return  \SimpleXMLElement[]|boolean  Boolean false on error or array of SimpleXMLElement objects.
	 *
	 * @since   1.0
	 */
	protected function &findGroup($group)
	{
		$false = false;
		$groups = array();
		$tmp = array();

		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return $false;
		}

		// Make sure there is actually a group to find.
		$group = explode('.', $group);

		if (!empty($group))
		{
			// Get any fields elements with the correct group name.
			$elements = $this->xml->xpath('//fields[@name="' . (string) $group[0] . '"]');

			// Check to make sure that there are no parent groups for each element.
			foreach ($elements as $element)
			{
				if (!$element->xpath('ancestor::fields[@name]'))
				{
					$tmp[] = $element;
				}
			}

			// Iterate through the nested groups to find any matching form field groups.
			for ($i = 1, $n = count($group); $i < $n; $i++)
			{
				// Initialise some loop variables.
				$validNames = array_slice($group, 0, $i + 1);
				$current = $tmp;
				$tmp = array();

				// Check to make sure that there are no parent groups for each element.
				/** @var \SimpleXMLElement $element */
				foreach ($current as $element)
				{
					// Get any fields elements with the correct group name.
					$children = $element->xpath('descendant::fields[@name="' . (string) $group[$i] . '"]');

					// For the found fields elements validate that they are in the correct groups.
					foreach ($children as $fields)
					{
						// Get the group names as strings for ancestor fields elements.
						$attrs = $fields->xpath('ancestor-or-self::fields[@name]/@name');
						$names = array_map('strval', $attrs ? $attrs : array());

						// If the group names for the fields element match the valid names at this
						// level add the fields element.
						if ($validNames == $names)
						{
							$tmp[] = $fields;
						}
					}
				}
			}

			// Only include valid XML objects.
			foreach ($tmp as $element)
			{
				if ($element instanceof \SimpleXMLElement)
				{
					$groups[] = $element;
				}
			}
		}

		return $groups;
	}

	/**
	 * Method to load, setup and return a FormField object based on field data.
	 *
	 * @param   string  $element  The XML element object representation of the form field.
	 * @param   string  $group    The optional dot-separated form group path on which to find the field.
	 * @param   mixed   $value    The optional value to use as the default for the field.
	 *
	 * @return  Field|boolean  The Field object for the field or boolean false on error.
	 *
	 * @since   1.0
	 */
	protected function loadField($element, $group = null, $value = null)
	{
		// Make sure there is a valid SimpleXMLElement.
		if (!($element instanceof \SimpleXMLElement))
		{
			return false;
		}

		// Get the field type.
		$type = $element['type'] ? (string) $element['type'] : 'text';

		// Load the Field object for the field.
		$field = FormHelper::loadFieldClass($type);

		// If the object could not be loaded, get a text field object.
		if ($field === false)
		{
			$field = FormHelper::loadFieldClass('text');
		}

		// Instantiate the Field object
		/** @var Field $field */
		$field = new $field;

		// Try to inject the text object into the field
		try
		{
			$field->setText($this->getText());
		}
		catch (\RuntimeException $exception)
		{
			// A Text object was not set, ignore the error and try to continue processing
		}

		/*
		 * Get the value for the form field if not set.
		 * Default to the translated version of the 'default' attribute
		 * if 'translate_default' attribute if set to 'true' or '1'
		 * else the value of the 'default' attribute for the field.
		 */
		if ($value === null)
		{
			$default = (string) $element['default'];

			// Try to translate the default value if translations are enabled
			try
			{
				$lang = $this->getText()->getLanguage();

				if (($translate = $element['translate_default']) && ((string) $translate == 'true' || (string) $translate == '1'))
				{
					if ($lang->hasKey($default))
					{
						$debug = $lang->setDebug(false);
						$default = $this->getText()->translate($default);
						$lang->setDebug($debug);
					}
					else
					{
						$default = $this->getText()->translate($default);
					}
				}
			}
			catch (\RuntimeException $exception)
			{
				// A Text object wasn't set to extract a Language object from, use our non-translated default
			}

			$value = $this->getValue((string) $element['name'], $group, $default);
		}

		// Setup the Field object.
		$field->setForm($this);

		if ($field->setup($element, $value, $group))
		{
			return $field;
		}
		else
		{
			return false;
		}
	}

	/**
	 * Method to synchronize any field, form or rule paths contained in the XML document.
	 *
	 * @return  boolean  True on success.
	 *
	 * @since   1.0
	 * @todo    Maybe we should receive all addXXXpaths attributes at once?
	 */
	protected function syncPaths()
	{
		// Make sure there is a valid Form XML document.
		if (!($this->xml instanceof \SimpleXMLElement))
		{
			return false;
		}

		// Get any addformpath attributes from the form definition.
		$paths = $this->xml->xpath('//*[@addformpath]/@addformpath');
		$paths = array_map('strval', $paths ? $paths : array());

		// Add the form paths.
		foreach ($paths as $path)
		{
			$path = JPATH_ROOT . '/' . ltrim($path, '/\\');
			FormHelper::addFormPath($path);
		}

		return true;
	}

	/**
	 * Method to validate a Field object based on field data.
	 *
	 * @param   \SimpleXMLElement  $element  The XML element object representation of the form field.
	 * @param   string             $group    The optional dot-separated form group path on which to find the field.
	 * @param   mixed              $value    The optional value to use as the default for the field.
	 * @param   Registry           $input    An optional Registry object with the entire data set to validate
	 *                                       against the entire form.
	 *
	 * @return  \Exception|boolean  Boolean true if field value is valid, Exception on failure.
	 *
	 * @since   1.0
	 * @throws  \InvalidArgumentException
	 * @throws  \UnexpectedValueException
	 */
	protected function validateField(\SimpleXMLElement $element, $group = null, $value = null, Registry $input = null)
	{
		$valid = true;

		// Check if the field is required.
		$required = ((string) $element['required'] == 'true' || (string) $element['required'] == 'required');

		if ($required)
		{
			// If the field is required and the value is empty return an error message.
			if (($value === '') || ($value === null))
			{
				$translate = $element['translate_default'] && ((string) $translate == 'true' || (string) $translate == '1');

				if ($element['label'])
				{
					$message = $translate ? $this->getText()->translate($element['label']) : $element['label'];
				}
				else
				{
					$message = $translate ? $this->getText()->translate($element['name']) : $element['name'];
				}

				// TODO - Language strings for our packages should be defined and loaded into the language object
				if ($translate)
				{
					$message = $this->getText()->sprintf('Field required: %s', $message);
				}
				else
				{
					$message = sprintf('Field required: %s', $message);
				}

				return new \RuntimeException($message);
			}
		}

		// Get the field validation rule.
		if ($type = (string) $element['validate'])
		{
			// Load the Rule object for the field.
			$rule = FormHelper::loadRuleClass($type);

			// If the object could not be loaded return an error message.
			if ($rule === false)
			{
				throw new \UnexpectedValueException(sprintf('%s::validateField() rule `%s` missing.', get_class($this), $type));
			}

			// Instantiate the Rule object
			/** @var Rule $rule */
			$rule = new $rule;

			// Run the field validation rule test.
			$valid = $rule->test($element, $value, $group, $input, $this);

			// Check for an error in the validation test.
			if ($valid instanceof \Exception)
			{
				return $valid;
			}
		}

		// Check if the field is valid.
		if ($valid === false)
		{
			// Does the field have a defined error message?
			$message   = (string) $element['message'];
			$translate = $element['translate_default'] && ((string) $translate == 'true' || (string) $translate == '1');

			if ($message)
			{
				$message = $translate ? $this->getText()->translate($element['message']) : $element['message'];

				return new \UnexpectedValueException($message);
			}
			else
			{
				$message = $translate ? $this->getText()->translate($element['label']) : $element['label'];

				// TODO - Language strings for our packages should be defined and loaded into the language object
				if ($translate)
				{
					$message = $this->getText()->sprintf('Invalid field: %s', $message);
				}
				else
				{
					$message = sprintf('Invalid field: %s', $message);
				}

				return new \UnexpectedValueException($message);
			}
		}

		return true;
	}

	/**
	 * Method to get an instance of a form.
	 *
	 * @param   string       $name     The name of the form.
	 * @param   string       $data     The name of an XML file or string to load as the form definition.
	 * @param   array        $options  An array of form options.
	 * @param   boolean      $replace  Flag to toggle whether form fields should be replaced if a field
	 *                                 already exists with the same group/name.
	 * @param   bool|string  $xpath    An optional xpath to search for the fields.
	 *
	 * @return  Form   Instance of this class.
	 *
	 * @since   1.0
	 * @throws  \InvalidArgumentException if no data provided.
	 * @throws  \RuntimeException if the form could not be loaded.
	 */
	public static function getInstance($name, $data = null, $options = array(), $replace = true, $xpath = false)
	{
		// Reference to array with form instances
		$forms = &static::$forms;

		// Only instantiate the form if it does not already exist.
		if (!isset($forms[$name]))
		{
			$data = trim($data);

			if (empty($data))
			{
				throw new \InvalidArgumentException(sprintf('%s(name, *%s*)', __METHOD__, gettype($data)));
			}

			// Instantiate the form.
			$forms[$name] = new static($name, $options);

			// Load the data.
			if (substr(trim($data), 0, 1) == '<')
			{
				if ($forms[$name]->load($data, $replace, $xpath) == false)
				{
					throw new \RuntimeException(__METHOD__ . ' could not load form');
				}
			}
			else
			{
				if ($forms[$name]->loadFile($data, $replace, $xpath) == false)
				{
					throw new \RuntimeException(__METHOD__ . ' could not load file');
				}
			}
		}

		return $forms[$name];
	}

	/**
	 * Adds a new child SimpleXMLElement node to the source.
	 *
	 * @param   \SimpleXMLElement  $source  The source element on which to append.
	 * @param   \SimpleXMLElement  $new     The new element to append.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected static function addNode(\SimpleXMLElement $source, \SimpleXMLElement $new)
	{
		// Add the new child node.
		$node = $source->addChild($new->getName(), trim($new));

		// Add the attributes of the child node.
		foreach ($new->attributes() as $name => $value)
		{
			$node->addAttribute($name, $value);
		}

		// Add any children of the new node.
		foreach ($new->children() as $child)
		{
			static::addNode($node, $child);
		}
	}

	/**
	 * Update the attributes of a child node
	 *
	 * @param   \SimpleXMLElement  $source  The source element on which to append the attributes
	 * @param   \SimpleXMLElement  $new     The new element to append
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected static function mergeNode(\SimpleXMLElement $source, \SimpleXMLElement $new)
	{
		// Update the attributes of the child node.
		foreach ($new->attributes() as $name => $value)
		{
			if (isset($source[$name]))
			{
				$source[$name] = (string) $value;
			}
			else
			{
				$source->addAttribute($name, $value);
			}
		}
	}

	/**
	 * Merges new elements into a source <fields> element.
	 *
	 * @param   \SimpleXMLElement  $source  The source element.
	 * @param   \SimpleXMLElement  $new     The new element to merge.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	protected static function mergeNodes(\SimpleXMLElement $source, \SimpleXMLElement $new)
	{
		// The assumption is that the inputs are at the same relative level.
		// So we just have to scan the children and deal with them.

		/** @var \SimpleXMLElement $child */
		foreach ($new->children() as $child)
		{
			$type = $child->getName();
			$name = $child['name'];

			// Does this node exist?
			$fields = $source->xpath($type . '[@name="' . $name . '"]');

			if (empty($fields))
			{
				// This node does not exist, so add it.
				static::addNode($source, $child);
			}
			else
			{
				// This node does exist.
				switch ($type)
				{
					case 'field':
						static::mergeNode($fields[0], $child);
						break;

					default:
						static::mergeNodes($fields[0], $child);
						break;
				}
			}
		}
	}
}