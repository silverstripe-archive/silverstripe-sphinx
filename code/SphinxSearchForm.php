<?php

/**
 * Provides SphinxSearch based SearchForm
 * 
 * @package sapphire
 * @subpackage search
 */
class SphinxSearchForm extends Form {
	
	/**
	 * @var int $pageLength How many results are shown per page.
	 * Relies on pagination being implemented in the search results template.
	 */
	protected $pageLength = 10;
	
	/**
	 * Classes to search
	 */	
	protected $classesToSearch = array('SiteTree', 'File');
	
	/**
	 * 
	 * @param Controller $controller
	 * @param string $name The name of the form (used in URL addressing)
	 * @param FieldSet $fields Optional, defaults to a single field named "Search". Search logic needs to be customized
	 *  if fields are added to the form.
	 * @param FieldSet $actions Optional, defaults to a single field named "Go".
	 * @param boolean $showInSearchTurnOn DEPRECATED 2.3
	 */
	function __construct($controller, $name, $fields = null, $actions = null, $showInSearchTurnOn = true, $args = array()) {
		$this->showInSearchTurnOn = $showInSearchTurnOn;
		$this->args = $args;
		
		if(!$fields) {
			$fields = new FieldSet(
				new TextField('Search', _t('SearchForm.SEARCH', 'Search')
			));
		}
		
		if(singleton('SiteTree')->hasExtension('Translatable')) {
			$fields->push(new HiddenField('locale', 'locale', Translatable::get_current_locale()));
		}
		
		if(!$actions) {
			$actions = new FieldSet(
				new FormAction("getResults", _t('SearchForm.GO', 'Go'))
			);
		}
		
		parent::__construct($controller, $name, $fields, $actions);
		
		$this->setFormMethod('get');
		$this->disableSecurityToken();
		
		$this->search_cache = array();
	}
	
	public function forTemplate() {
		return $this->renderWith(array(
			'SphinxSearchForm',
			'SearchForm',
			'Form'
		));
	}

	/**
	 * Set the classes to search.
	 * Currently you can only choose from "SiteTree" and "File", but a future version might improve this. 
 	 */
	function classesToSearch($classes) {
		foreach ($classes as $class) {
			if (!Object::has_extension($class, 'SphinxSearchable')) user_error("SphinxSearchForm::classesToSearch() passed illegal class $class. Must have SphinxSearchable extension applied", E_USER_ERROR);
		}
		$this->classesToSearch = $classes;
	}

	public function getSphinxResult($pageLength=null, $data=null) {
		if(!isset($data)) $data = $_REQUEST;
		if(!$pageLength) $pageLength = $this->pageLength;
				
		$keywords = $data['Search'];
		$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
		
		$cachekey = $keywords.':'.$start;
		if (!isset($this->search_cache[$cachekey])) {
			$this->search_cache[$cachekey] = SphinxSearch::search($this->classesToSearch, $keywords, array_merge_recursive(array(
				'exclude' => array('_classid' => SphinxSearch::unsignedcrc('Folder')),
				'start' => $start,
				'pagesize' => $pageLength
			), $this->args));
		}
		
		return $this->search_cache[$cachekey];
	}
	
	/**
	 * Return dataObjectSet of the results using $_REQUEST to get info from form.
	 * Wraps around {@link searchEngine()}.
	 * 
	 * @param int $pageLength DEPRECATED 2.3 Use SearchForm->pageLength
	 * @param array $data Request data as an associative array. Should contain at least a key 'Search' with all searched keywords.
	 * @return DataObjectSet
	 */
	public function getResults($pageLength = null, $data = null){
		return $this->getSphinxResult($pageLength, $data)->Matches;
	}
	
	public function getSuggestion($pageLength = null, $data = null){
		return $this->getSphinxResult($pageLength, $data)->Suggestion;
	}
	
	/**
	 * Get the search query for display in a "You searched for ..." sentence.
	 * 
	 * @param array $data
	 * @return string
	 */
	public function getSearchQuery($data = null) {
		// legacy usage: $data was defaulting to $_REQUEST, parameter not passed in doc.silverstripe.com tutorials
		if(!isset($data)) $data = $_REQUEST;
		
		return Convert::raw2xml($data['Search']);
	}
	
	/**
	 * Set the maximum number of records shown on each page.
	 * 
	 * @param int $length
	 */
	public function setPageLength($length) {
		$this->pageLength = $length;
	}
	
	/**
	 * @return int
	 */
	public function getPageLength() {
		// legacy handling for deprecated $numPerPage
		return (isset($this->numPerPage)) ? $this->numPerPage : $this->pageLength;
	}

}

?>