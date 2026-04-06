<?php

class OCDrawMapType extends eZDataType
{
    const DATA_TYPE_STRING = 'ocdrawmap';

    const DEFAULT_SUBATTRIBUTE_TYPE = 'location_rpt';
    
    const FIELD_TYPE_MAP = 'rpt';

    function __construct()
    {
        $this->eZDataType(self::DATA_TYPE_STRING, ezpI18n::tr('kernel/classes/datatypes', 'Draw Map', 'Datatype name'),
            array(
                'serialize_supported' => true,
                'object_serialize_map' => array('data_text' => 'text')
            )
        );
    }

    /**
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @param int $currentVersion
     * @param eZContentObjectAttribute $originalContentObjectAttribute
     */
    function initializeObjectAttribute($contentObjectAttribute, $currentVersion, $originalContentObjectAttribute)
    {
        if ($currentVersion != false) {
            $dataText = $originalContentObjectAttribute->attribute("data_text");
            $contentObjectAttribute->setAttribute("data_text", $dataText);
        } else {
            $contentClassAttribute = $contentObjectAttribute->contentClassAttribute();
            $default = $contentClassAttribute->attribute('data_text1');
            if ($default !== '' && $default !== NULL) {
                $contentObjectAttribute->setAttribute('data_text', $default);
            }
        }
    }

    /**
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @return string
     */
    function objectAttributeContent($contentObjectAttribute)
    {        
        $content = array(
            'type' => '',
            'color' => '',
            'source' => '',
            'geo_json' => json_encode(new \Opencontent\Opendata\GeoJson\FeatureCollection()),
        );
        $data = $contentObjectAttribute->attribute('data_text');
        if ($data != ''){
            $content = json_decode($data, 1);
            //bc
            if ($content['type'] == 'ocql_geo'){
                $content['type'] = 'geojson';
            }
        }

        return $content;
    }

    /**
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @return bool
     */
    function hasObjectAttributeContent($contentObjectAttribute)
    {
        if( trim($contentObjectAttribute->attribute('data_text')) != ''){
            $data = $contentObjectAttribute->attribute('data_text');
            $content = json_decode($data, 1);
            $geoJson = json_decode($content['geo_json'], 1);

            return count($geoJson['features']) > 0;
        }

        return false;
    }

    /**
     * @param eZHTTPTool $http
     * @param $base
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @return bool
     */
    function fetchObjectAttributeHTTPInput($http, $base, $contentObjectAttribute)
    {
        if ($http->hasPostVariable($base . '_ocdrawmap_data_text_' . $contentObjectAttribute->attribute('id'))) {
            $geoJSON = $http->postVariable($base . '_ocdrawmap_data_text_' . $contentObjectAttribute->attribute('id'));
            $content = array(
                'geo_json' => $geoJSON
            );
            if ($http->hasPostVariable($base . '_ocdrawmap_osm_url_' . $contentObjectAttribute->attribute('id'))) {
                $content['source'] = $http->postVariable($base . '_ocdrawmap_osm_url_' . $contentObjectAttribute->attribute('id'));
            }
            if ($http->hasPostVariable($base . '_ocdrawmap_osm_type_' . $contentObjectAttribute->attribute('id'))) {
                $content['type'] = $http->postVariable($base . '_ocdrawmap_osm_type_' . $contentObjectAttribute->attribute('id'));
            }
            if ($http->hasPostVariable($base . '_ocdrawmap_osm_color_' . $contentObjectAttribute->attribute('id'))) {
                $content['color'] = $http->postVariable($base . '_ocdrawmap_osm_color_' . $contentObjectAttribute->attribute('id'));
            }
            $contentObjectAttribute->setAttribute('data_text', json_encode($content));
            return true;
        }
        return false;
    }

    function isIndexable()
    {
        return true;
    }

    public static function getWKTList($contentObjectAttribute)
    {
        $content = $contentObjectAttribute->content();
        $json = json_decode($content['geo_json'], 1);
        foreach ($json['features'] as $feature) {
            $geometry = $feature['geometry'];
            switch ($geometry['type']) {
                case 'MultiPolygon':                    
                    foreach ($geometry['coordinates'] as $polygonWrapper) {
                        foreach ($polygonWrapper as $polygon) {                            
                            $polygonCoordinates = array();
                            foreach ($polygon as $coordinates) {
                                $polygonCoordinates[] = $coordinates[1] . ' ' . $coordinates[0];
                            }                        
                            $data[] = "POLYGON((" . implode(', ', $polygonCoordinates) . "))";
                        }
                    }
                    break;

                case 'Polygon':
                    foreach ($geometry['coordinates'] as $polygon) {
                        $polygonCoordinates = array();
                        foreach ($polygon as $coordinates) {
                            $polygonCoordinates[] = $coordinates[1] . ' ' . $coordinates[0];
                        }                        
                        $data[] = "POLYGON((" . implode(', ', $polygonCoordinates) . "))";
                    }
                    break;

                case 'LineString':
                    foreach ($geometry['coordinates'] as $point) {
                        if (isset($point[1]) && count($point) == 2 && is_numeric($point[1])){
                            $data[] = $point[1] . ' ' . $point[0];              
                        }else{
                            foreach ($point as $coordinates) {                                
                                $data[] = $coordinates[1] . ' ' . $coordinates[0];              
                            }
                        }
                    }
                    break;

                case 'Point':
                    if (isset($geometry['properties']['radius'])){
                        $data[] = "Circle(" . $geometry['coordinates'][1] . ' ' . $geometry['coordinates'][0] . " d=" . $geometry['properties']['radius'] . ")";   
                    }else{
                        $data[] = $geometry['coordinates'][1] . ' ' . $geometry['coordinates'][0];   
                    }
                    break;
                
                default:
                    
                    break;
            }
        }

        return $data;
    }

    function metaData($contentObjectAttribute)
    {
        self::addSolrFieldTypeMap();

        return '';
    }

    /**
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @return string
     */
    function toString($contentObjectAttribute)
    {
        return $contentObjectAttribute->attribute('data_text');
    }

    /**
     * @param eZContentObjectAttribute $contentObjectAttribute
     * @param $string
     */
    function fromString($contentObjectAttribute, $string)
    {
        $contentObjectAttribute->setAttribute( 'data_text', $string );
        $contentObjectAttribute->store();
    }

    public static function addSolrFieldTypeMap()
    {
        $datatypeMapList = eZINI::instance('ezfind.ini')->variable('SolrFieldMapSettings', 'DatatypeMap');
        if (isset($datatypeMapList['ocdrawmap']) && $datatypeMapList['ocdrawmap'] == 'location_rpt') {
            if (!isset(ezfSolrDocumentFieldName::$FieldTypeMap[self::DEFAULT_SUBATTRIBUTE_TYPE])) {
                ezfSolrDocumentFieldName::$FieldTypeMap[self::DEFAULT_SUBATTRIBUTE_TYPE] = self::FIELD_TYPE_MAP;
            }
            return true;
        }

        return false;
    }

}

eZDataType::register( OCDrawMapType::DATA_TYPE_STRING, 'OCDrawMapType' );

