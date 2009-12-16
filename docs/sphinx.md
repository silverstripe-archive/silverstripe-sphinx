# Server Installation

# Applying the Decorator

`class MyPage extends Page {
	static $db = array(
		...
	);

	static $extensions = array(
		'SphinxSearchable'
	);

	static $sphinx = array(
		"search_fields" => array("a", "b", "c"),
		"filter_fields" => array("a", "c"),
		"sort_fields" => array("Title"),
		"extra_fields" => array("_contenttype" => "Page::getComputedValue"),
		'filterable_many_many' => '*',
		'extra_many_many' => array('documents' => 'select (1468840814<<32) | PageID AS id, DocumentID AS Documents FROM Page_Documents')
		"mode" => "xmlpipe",
		"external_content" => array("field" => array("myclass", "somefunc"))
	);
`

## Properties of the $sphinx Static

* search_fields - an array of fields in this class to be indexed. If $excludeByDefault (see below) is false and searchFields is not supplied, all
  fields are index. If $excludeByDefault is true, this must be supplied.
* filter_fields - an array of fields in this class that can be filtered. If $excludeByDefault is false and filterFields is not supplied, all
  non-string fields will be made filterable (including from has_one relationships). If $excludeByDefault is true, this must be supplied to
  have fields made filterable.
* sort_fields - an array of fields that can be sorted on. Similar to filterFields in that all fields listed here are added as filters, but this
  can include string fields. You can only sort on fields that:
  * special attributes created by sphinx
  * non-string fields that are in filterFields (or any non-int if $excludeByDefault is false)
  * string fields that are explicitly defined in sortFields. Because Sphinx cannot filter on string fields, special behaviour is
    implemented to create proxy int filter fields, which are then sorted more accurately once the result is returned from the sphinx process.
* extra_fields - defines extra fields into the main SQL used for generating indexes, and includes them as attributes in the index. The value can
  be a SQL expression, but can also be of the form "class::method" which is called using call_user_func to get the value. The resulting value should
  be an int.
* filterable_many_many - an array of many-many relationship names, or '*' for all many-many relationships on this class. These are added as
  filters to the index. 
* extra_many_many - this allows injection of many-many attributes bypassing sapphire's generation of SQL automatically of the relationship. This
  is specifically useful for working around an issue with sapphire 2.4, which generates ANSI compliant SQL statements, but these fail in
  sphinx indexing if the database server is not set to use ANSI compliant, because there is no way to control ansi-mode for the query
  that retrieves many-many data in sphinx (a bug in sphinx: http://www.sphinxsearch.com/bugs/view.php?id=394). This is not an issue
  if mode is 'xmlpipe'.
* mode - this determines the mode used by the sphinx indexer to retrieve data. One of the values:
  * 'sql' (default) - SQL statements are used. The statements are written to the sphinx.conf file. Indexer handles database connections itself.
  * 'xmlpipe' - the indexer runs a command that invokes the SphinxXMLPipe controller to get the data to index as an XML stream. Experimental
    at this stage (well, more experimental than SQL). This is likely to be slower than SQL indexing. Has the advantage that content outside
    the database can be included in the index.
* external_content provides a hook to provide additional content to add to the search index. The value provided is passed as the function
  argument to call_user_func, so can be a function, array($instance, $functionName) for an instance method, or
  array($className, $functionName) for a static function. NOTE: This is only applicable when mode is 'xmlpipe'. It is ignored if
  mode is 'sql'. The function is called with a single parameter, the ID of the decorated instance.

## Parameters of the Constructor

* excludeByDefault (default false) When false, all properties on sub-classes are automatically indexed, and all non-string fields are
  made filterable. This gives maximum searchability with the cost of potentially increasing the number of indices, and increasing the
  emory footprint of searchd. If this is set to true, fields in subclasses are excluded from indexing unless the sub-class specifically
  defines $searchFields, $filterFields.
  e.g. `static $extensions = array('SphinxSearchable(true)'); // disable automatic inclusion of all subclass fields`

# Managing Larger Configurations

Sphinx performance and resource usage is affected by a number of factors, including:

* Number of indices
* Whether or not deltas are used
* The number of attributes in the indices. These are kept in searchd memory.

Ways to control these factors include:

* Attach the decorator at a deeper level in the class tree. e.g. instead of decorating Page, decorate specific subclasses of Page.
* Use the excludeByDefault option on the constructor, and explicitly control the search, filter and order fields on the class.
* For classes that change very infrequently, or are small, consider disabling delta indices.
* For versioned pages, if search is not required in the CMS, consider explicit control over the stages that are indexed. (e.g. only index Live
  if searching is only enabled  at the front end.)

# Known Issues

## Re-indexing Many-Many Relationships on Write

Currently many-many relationships are not re-indexed on write, as there is no way to reliably detect changes in the components if the decorated
object doesn't change. So if changes are made in a M-M, these need to be re-indexed by calling $do->sphinxComponentsChanged() on the decorated
instance. This will re-index the object in the delta. Otherwise the M-M changes will be picked up at the next primary re-index.

## Slow Saving with XML Pipes

Saving pages that are indexed using XML pipes can be very slow in the CMS. This is due to the relatively high overhead of invoking
the framework from the command line interactively in order to re-index the delta. This is compounded by versioning (which doubles the number
of writes), cmsworkflow and any other decorator that introduces additional write() calls to the data object.

# Troubleshooting

# Is the Sphinx configuration file being Created?

* Check permissions
* When running a dev/build via sake, don't include flush=all, as this requires you to be logged in as admin, and will generate an invalid sphinx.conf

# Can the Indexer Build all the Indices?

* Error is 'No local indices'
* Check that the class being searched actually have indices
* Check the sphinx.conf to ensure config for that class is correct.
* Use 'indexer' on the config file to see if there are errors.

## Errors

### "failed to send client protocol version"

This error may be intermittent. It is symptomatic of a limit being reached on the number of indices.
Ways to reduce the number of indicies:

## Sorting Issues

* If sorting on a text field, it must be declared as a sortable column

# Further Enhancements

## Performance of XML Pipes Write

Two things that would help, especially performance of page publishes in the CMS when using xml pipes:

* Queue the requests, and only re-index once on PHP shutdownm, rather than re-index on each write.
* Possible invoke the reindexer asychronously, so the user does not have any panelty.
