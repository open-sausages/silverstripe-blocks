<?php

/**
 * @package silverstipe blocks
 * @author Shea Dawson <shea@silverstripe.com.au>
 */
class BlocksSiteTreeExtension extends SiteTreeExtension {

	private static $db = array(
		'InheritGlobalBlocks' => 'Boolean',
		'InheritBlockSets' => 'Boolean'
	);
	private static $many_many = array(
		'Blocks' => 'Block',
		'DisabledBlocks' => 'Block'
	);
	public static $many_many_extraFields = array(
		'Blocks' => array(
			'Sort' => 'Int',
			'BlockArea' => 'Varchar'
		)
	);
	private static $defaults = array(
		'InheritGlobalBlocks' => 1,
		'InheritBlockSets' => 1
	);
	private static $dependencies = array(
		'blockManager' => '%$blockManager',
	);
	public $blockManager;

	/**
	 * Block manager for Pages
	 * */
	public function updateCMSFields(FieldList $fields) {
		if ($fields->fieldByName('Root.Blocks')) {
			return;
		}

		$areas = $this->blockManager->getAreasForPageType($this->owner->ClassName);

		if ($areas && count($areas)) {
			$fields->addFieldToTab('Root.Blocks', 
					LiteralField::create('PreviewLink', $this->areasPreviewButton()));

			// Set sort -> moved to 
//			$sortfields = array(
////				"FIELD(Area, '" . implode("','", array_keys($areas)) . "')" => '',
//				'Weight' => 'ASC', 'Title' => 'ASC');

			// Blocks related directly to this Page 
			$gridConfig = GridFieldConfig_BlockManager::create()
				->addExisting($this->owner->class)
				->addBulkEditing()
				->addComponent($orderablerows = new GridFieldOrderableRows()) // Leave this at default 'Sort'
				;

			//$gridSource = $this->getBlockList(null, false);
			$gridSource = $this->owner->Blocks();
				// sort by FIELD Area to force the same order as areas are declared in config
//				->sort($sortfields);

			$fields->addFieldToTab('Root.Blocks', 
					GridField::create('Blocks', 'Blocks', $gridSource, $gridConfig));

			// Blocks inherited from SiteConfig and BlockSets
			$fields->addFieldToTab('Root.Blocks', 
					HeaderField::create('InheritedBlocksHeader', 'Inherited Blocks'));
			$fields->addFieldToTab('Root.Blocks', 
					CheckboxField::create('InheritGlobalBlocks', 
							'Inherit Global Blocks from Site Configuration'));
			$fields->addFieldToTab('Root.Blocks', 
					CheckboxField::create('InheritBlockSets', 'Inherit Blocks from Block Sets'));

			$allInherited = $this->getBlockList(null, false, false, true, true, true, true);
			if ($allInherited->count()) {
				$fields->addFieldToTab('Root.Blocks', 
						ListBoxField::create('DisabledBlocks', 'Disable Inherited Blocks', 
								$allInherited->map('ID', 'Title'), null, null, true)
							->setDescription('Select any inherited blocks that you would not like displayed on this page.')
				);

				$activeInherited = $this->getBlockList(null, false, false, true, true, false);
				//var_dump($activeInherited->count());
				if ($activeInherited->count()) {

					$fields->addFieldToTab('Root.Blocks', 
							GridField::create('InheritedBlockList', 'Inherited Blocks', $activeInherited, 
									GridFieldConfig_BlockManager::create(false, false, false)));
				}
			} else {
				$fields->addFieldToTab('Root.Blocks', 
						ReadonlyField::create('DisabledBlocksReadOnly', 'Disable Inherited Blocks', 
								'This page has no inherited blocks to disable.'));
			}
		} else {
			$fields->addFieldToTab('Root.Blocks', 
					LiteralField::create('Blocks', 'This page type has no Block Areas configured.'));
			
		}
	}
	
	/**
	 * Fallback/migration from Weight to Sort (Alas, doesn't work)
	 */
//	public function Sort(){
//		if($this->owner->Sort) return $this->owner->Sort;
//		return $this->owner->Weight;
//	}

	/**
	 * Override Blocks to use sort 
	 */
//	public function Blocks(){
//		Debug::dump('test');
//		return $this->owner->getManyManyComponents('Blocks')->sort('Sort');
//			// sort by FIELD Area to force the same order as areas are declared in config
////			->sort(array(
////				"FIELD(Area, 'Header','BeforeContent','InsideContent','AfterContent')" => '',
////				'Sort'=>'ASC', 'Title' => 'ASC'));
//	}

	/**
	 * Block manager for Sites (multisites module)
	 * */
	public function updateSiteCMSFields(FieldList $fields) {
		$fields->addFieldToTab('Root.GlobalBlocks', 
				GridField::create('Blocks', 'Blocks', $this->owner->Blocks(), 
						GridFieldConfig_BlockManager::create()));
	}

	/**
	 * Called from templates to get rendered blocks for the given area
	 * @param string $area
	 * @param integer $limit Limit the items to this number, or null for no limit
	 */
	public function BlockArea($area, $limit = null) {
		if ($this->owner->ID <= 0)
			return; // blocks break on fake pages ie Security/login

		$publishedOnly = Versioned::current_stage() == 'Stage' ? false : true;
		$list = $this->getBlockList($area, $publishedOnly);

		foreach ($list as $block) {
			if (!$block->canView())
				$list->remove($block);
		}

		if ($limit !== null) {
			$list = $list->limit($limit);
		}

		$data['BlockArea'] = $list;
		$data['AreaID'] = $area;

		$data = $this->owner->customise($data);


		$template[] = 'BlockArea_' . $area;
		if (SSViewer::hasTemplate($template)) {
			return $data->renderWith($template);
		} else {
			return $data->renderWith('BlockArea');
		}

		//return $data->renderWith(array("BlockArea_$area", "BlockArea"));
	}

	/**
	 * Get a merged list of all blocks on this page and ones inherited from SiteConfig, BlockSets etc 
	 * 
	 * @param string|null $area filter by block area
	 * @param boolean $publishedOnly only return published blocks
	 * @param boolean $includeNative Include blocks directly assigned to this page
	 * @param boolean $includeGlobal Include global blocks
	 * @param boolean $includeSets Include block sets
	 * @param boolean $includeDisabled Include blocks that have been explicitly excluded from this page
	 * i.e. global blocks added to the "disable inherited blocks" list
	 * @return ArrayList
	 * */
	public function getBlockList(
			$area = null, $publishedOnly = true, $includeNative = true, 
			$includeGlobal = true, $includeSets = true, $includeDisabled = false ) {

		////// DataList rewrite ///////
		// $blocks = DataList::create('Block');
		// $filter = array();
		// if($includeNative){
		// 	$siteTreeIDs[] = $this->owner->ID;
		// }
		// if($includeGlobal){
		// 	if($this->owner->InheritGlobalBlocks){
		// 		$siteTreeIDs[] = $this->owner->SiteID;
		// 	}
		// }
		// if(isset($siteTreeIDs)){
		// 	$blocks->innerJoin(
		// 		"SiteTree_Blocks", 
		// 		"SiteTree_Blocks.SiteTreeID = SiteTree.ID", 
		// 		"Pages"
		// 	);
		// 	$filter["Pages.ID"] = $siteTreeIDs;
		// }
		// if($includeSets){
		// 	if($this->owner->InheritBlockSets){
		// 		if($blocksFromSets = $this->getAppliedSets($area, $includeDisabled)){
		// 			$setIDsFilter = $blocksFromSets->column('ID');
		// 		}
		// 	}
		// }
		// if(isset($setIDsFilter)){
		// 	$blocks->innerJoin(
		// 		"BlockSet_Blocks", 
		// 		"BlockSet_Blocks.BlockSetID = BlockSet.ID", 
		// 		"BlockSets"
		// 	);
		// 	$filter["BlockSets.ID"] = $setIDsFilter;
		// }
		// PROBLEM 1 - $filter BREAKS gf when redirecting after adding new block - x page filters...
		// this WORKS though
		// $filter = array(
		// 	'ID' => 4,
		// 	'Area' => 'BeforeContent'
		// );
		// PROBLEM 2 - filterAny does not seem to work with x table filters either, it just behaves like filter...
		// PROBLEM 3 - Cant use ArrayList instead of DataList because that also 
		// Workaround? Use ArrayList for now for frontend
		// in backend, display the different sources in their own gridfields 
		// 
		//$blocks = $blocks->filter($filter);	
		//$ids = implode(',', $siteTreeIDs);
		//$blocks = $blocks->where("(Pages.ID IN ($ids))");
		//return $blocks;
		/////// END REWRITE /////

		$blocks = ArrayList::create();

		// get blocks directly linked to this page
		if ($includeNative) {
			// Gridfieldsortablerows (Sort) - instead of default_sort (Weight)
			//$nativeBlocks = $this->owner->Blocks();
			$nativeBlocks = $this->owner->getManyManyComponents('Blocks')->sort('Sort');
			if ($area)
				$nativeBlocks = $nativeBlocks->filter('BlockArea', $area);
			if ($nativeBlocks->count()) {
				foreach ($nativeBlocks as $block) {
					$block->InheritedFrom = '-';
					$blocks->add($block);
				}
			}
		}

		// get blocks inherited from SiteConfig
		if ($includeGlobal && $this->owner->InheritGlobalBlocks 
				&& ($inheritedBlocks = $this->getInheritedGlobalBlocks($area, $includeDisabled))
		) {
			// merge inherited sources
			foreach ($inheritedBlocks as $block) {
				if (!$blocks->find('ID', $block->ID)) {
					$block->InheritedFrom = 'Global Blocks';
					$blocks->unshift($block);
				}
			}
		}

		// get blocks from BlockSets
		if ($includeSets && $this->owner->InheritBlockSets 
				&& ($blocksFromSets = $this->getBlocksFromAppliedBlockSets($area, $includeDisabled))
		) {
			// merge set sources
			foreach ($blocksFromSets as $block) {
				if (!$blocks->find('ID', $block->ID)) {
					$block->InheritedFrom = 'Block Set';
					$blocks->unshift($block);
				}
			}
		}

		// filter out unpublished blocks?
		$blocks = $publishedOnly ? $blocks->filter('Published', 1) : $blocks;

		// TODO: combine merges with Sort from OrderableRows
		//$blocks = $blocks->sort(Config::inst()->get('Block', 'default_sort'));
		//$blocks = $blocks->sort(array('Sort'=>'ASC', 'Title' => 'ASC'));

		return $blocks;
	}

	/**
	 * Get Blocks that are directly related to this page that are published
	 * @return DataList
	 * */
	public function getPublishedBlocks() {
		return $this->owner->Blocks()->filter('Published', 1);
	}

	/**
	 * Get Global Blocks that are inherited from SiteConfig
	 * @param string $area block area to filter by
	 * @param boolean $includeDisabled global blocks disabled on this page are not included by default 
	 * @return DataList
	 * */
	public function getInheritedGlobalBlocks($area = null, $includeDisabled = false) {
		$blocks = $this->getGloablBlockSource()->Blocks();

		if ($area) {
			$blocks = $blocks->filter('BlockArea', $area);
		}

		if (!$includeDisabled) {
			$disabledIDs = $this->owner->DisabledBlocks()->column('ID');
			if (count($disabledIDs)) {
				$blocks = $blocks->exclude('ID', $disabledIDs);
			}
		}

		return $blocks;
	}

	/**
	 * The source of "Global" Blocks may be Site or SiteConfig
	 * @return Site|SiteConfig
	 * */
	public function getGloablBlockSource() {
		if (class_exists('Multisites')) {
			return $this->owner->Site();
		} else {
			return SiteConfig::current_site_config();
		}
	}

	/**
	 * Get Any BlockSets that apply to this page 
	 * @todo could be more efficient
	 * @return ArrayList
	 * */
	public function getAppliedSets() {

		$sets = BlockSet::get()->filter('PageTypesValue:partialMatch', sprintf(':"%s"', $this->owner->ClassName));
		$list = ArrayList::create();
		$ancestors = $this->owner->getAncestors()->column('ID');

		foreach ($sets as $set) {
			$restrictedToParerentIDs = $set->PageParents()->column('ID');
			if (count($restrictedToParerentIDs) && count($ancestors)) {
				foreach ($ancestors as $ancestor) {
					if (in_array($ancestor, $restrictedToParerentIDs)) {
						$list->add($set);
						continue;
					}
				}
			} else {
				$list->add($set);
			}
		}
		return $list;
	}

	/**
	 * Get all Blocks from BlockSets that apply to this page 
	 * @return ArrayList
	 * */
	public function getBlocksFromAppliedBlockSets($area = null, $includeDisabled = false) {
		$sets = $this->getAppliedSets();

		if (!$sets)
			return;

		$blocks = ArrayList::create();
		foreach ($sets as $set) {
			$setBlocks = $set->Blocks();
			if ($includeDisabled) {
				$setBlocks = $setBlocks->exclude('ID', $this->owner->DisabledBlocks()->column('ID'));
			}

			if ($area) {
				$setBlocks = $setBlocks->filter('BlockArea', $area);
			}
			$blocks->merge($setBlocks);
		}
		$blocks->removeDuplicates();

		if ($blocks->count() == 0)
			return;

		return $blocks;
	}

	/**
	 * Get's the link for a block area preview button
	 * @return string
	 * */
	public function areasPreviewLink() {
		return Controller::join_links($this->owner->Link(), '?block_preview=1');
	}

	/**
	 * Get's html for a block area preview button
	 * @return string
	 * */
	public function areasPreviewButton() {
		return "<a class='ss-ui-button ss-ui-button-small' style='font-style:normal;' href='" . $this->areasPreviewLink() . "' target='_blank'>Preview Block Areas for this page</a>";
	}

}
