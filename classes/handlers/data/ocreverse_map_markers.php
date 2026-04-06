<?php

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\ContentSearch;
use Opencontent\Opendata\Api\Values\Content;


class DataHandlerOCReverseMapMarkers implements OpenPADataHandlerInterface
{

  public $contentType = 'geojson';

  private $query = '';
  private $attribute = '';
  private $classIdentifier = '';
  private $maps = array();

  public function __construct(array $Params)
  {
      $this->contentType = eZHTTPTool::instance()->getVariable('contentType', $this->contentType);
  }

  private function load( $hashIdentifier )
  {
    $query           = $this->query;
    $attribute       = $this->attribute;
    $classIdentifier = $this->classIdentifier;
    $args = compact(array("hashIdentifier", "query", "attribute", "classIdentifier"));

    if ( eZINI::instance()->variable('DebugSettings', 'DebugOutput') == 'enabled' ) {
      return self::find( $query, $attribute, $classIdentifier );
    } else {
      if (!isset($this->maps[$hashIdentifier])) {
        $this->maps[$hashIdentifier] = OCMapsCacheManager::getCacheManager($hashIdentifier)->processCache(
          array('OCMapsCacheManager', 'retrieveCache'),
          array(__CLASS__, 'generateCache'),
          null,
          null,
          $args
        );
      }
      return $this->maps[$hashIdentifier];
    }
  }

  public static function generateCache( $file, $args )
  {

    extract( $args );
    $content = self::find( $query, $attribute, $classIdentifier );

    return array(
      'content' => $content,
      'scope' => 'maps-cache',
      'datatype' => 'php',
      'store' => true
    );
  }

  protected static function findAll($query, $languageCode = null, array $limitation = null)
  {

    $contentRepository = new ContentRepository();
    $contentSearch = new ContentSearch();
    $currentEnvironment = new FullEnvironmentSettings();
    $parser = new ezpRestHttpRequestParser();
    $request = $parser->createRequest();
    $currentEnvironment->__set('request', $request);

    $contentRepository->setEnvironment($currentEnvironment);
    $contentSearch->setEnvironment($currentEnvironment);


    $hits = array();
    $count = 0;
    $facets = array();
    $query .= ' and limit ' . $currentEnvironment->getMaxSearchLimit();
    eZDebug::writeNotice($query, __METHOD__);
    while ($query) {
      $results = $contentSearch->search($query, $limitation);
      $count = $results->totalCount;
      $hits = array_merge($hits, $results->searchHits);
      $facets = $results->facets;
      $query = $results->nextPageQuery;
    }

    $result = new \Opencontent\Opendata\Api\Values\SearchResults();
    $result->searchHits = $hits;
    $result->totalCount = $count;
    $result->facets = $facets;

    return $result;
  }

  protected static function find( $query, $attribute, $classIdentifier)
  {
    $featureData = new DataHandlerTnFamMapMarkersGeoJsonFeatureCollection();
    $language    = eZLocale::currentLocaleCode();

    $classQuery = "classes [$classIdentifier]";

    $data = self::findAll($classQuery, $language);
    $objIds = array();

    if ($data->totalCount > 0) {
      foreach ($data->searchHits as $hit) {
        $objIds [] = $hit['data'][$language][$attribute]['content'][0]['id'];
      }
    }
    array_unique($objIds);

    if (eZHTTPTool::instance()->hasGetVariable('query')) {
      $query .= ' and raw[meta_id_si] in  ['. implode(',', $objIds) .']';
      try {
        $data = self::findAll($query, $language);

        if ($data->totalCount > 0) {
          $result['facets'] = $data->facets;

          foreach ($data->searchHits as $hit) {
            try {
              $properties = array(
                'id' => $hit['metadata']['id'],
                'type' => $hit['metadata']['classIdentifier'],
                'class' => $hit['metadata']['classIdentifier'],
                'name' => $hit['metadata']['name'][$language],
                'url' => '/content/view/full/' . $hit['metadata']['mainNodeId'],
                'popupContent' => '<em>Loading...</em>'
              );

              if (!empty($hit['data'][$language]['geo']['content']['longitude']) && !empty($hit['data'][$language]['geo']['content']['latitude'])) {
                $feature = new OCMapMarkersGeoJsonFeature($hit['metadata']['id'], array($hit['data'][$language]['geo']['content']['longitude'], $hit['data'][$language]['geo']['content']['latitude']), $properties);
                $featureData->add($feature);
              }

            } catch (Exception $e) {
              eZDebug::writeError($e->getMessage(), __METHOD__);
            }
          }
          $result['content'] =  $featureData;
          return json_encode($result);
        }

      } catch (Exception $e) {
        eZDebug::writeError($e->getMessage() . " in query $query", __METHOD__);
      }
    }
  }

  public function getData()
  {
    if ($this->contentType == 'geojson') {
      $featureData = new OCMapMarkersGeoJsonFeatureCollection();

      if (eZHTTPTool::instance()->hasGetVariable('classIdentifier') && eZHTTPTool::instance()->hasGetVariable('attribute')) {

        $this->classIdentifier = eZHTTPTool::instance()->getVariable('classIdentifier');
        $this->query = eZHTTPTool::instance()->getVariable('query');
        $this->attribute = eZHTTPTool::instance()->getVariable('attribute');

        return json_decode( $this->load( md5(trim($this->query . '-' . $this->attribute)) ), true ) ;

      }
    } elseif ($this->contentType == 'marker') {
      $view = eZHTTPTool::instance()->getVariable('view', 'panel');
      $id = eZHTTPTool::instance()->getVariable('id', 0);
      $object = eZContentObject::fetch($id);
      if ($object instanceof eZContentObject && $object->attribute('can_read')) {
        $tpl = eZTemplate::factory();
        $tpl->setVariable('object', $object);
        $tpl->setVariable('node', $object->attribute('main_node'));
        $result = $tpl->fetch('design:node/view/' . $view . '.tpl');
        $data = array('content' => $result);
      } else {
        $data = array('content' => '<em>Private</em>');
      }
      return $data;
    }
  }
}


