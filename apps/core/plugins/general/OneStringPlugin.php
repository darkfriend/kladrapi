<?php

namespace Kladr\Core\Plugins\General {

    use \Kladr\Core\Plugins\Base\IPlugin,
        \Phalcon\Mvc\User\Plugin,
        \Phalcon\Http\Request,
        \Kladr\Core\Plugins\Base\PluginResult,
        \Kladr\Core\Plugins\Tools\Tools,
        \Kladr\Core\Models\Complex,
        \Kladr\Core\Models\KladrFields;
        
    /*
     * Kladr\Core\Plugins\General\OneStringPlugin
     * 
     * Плагин для поиска объектов одной строкой
     * 
     * @author Y. Lichutin
     */
    class OneStringPlugin extends Plugin implements IPlugin 
    {

        /**
         * Кэш
         * 
         * @var Kladr\Core\Plugins\Tools\Cache 
         */
        public $cache;        
        
        /**
         * Выполняет обработку запроса
         * 
         * @param \Phalcon\Http\Request $request
         * @param \Kladr\Core\Plugins\Base\PluginResult $prevResult
         * @return \Kladr\Core\Plugins\Base\PluginResult
         */
        public function process(Request $request, PluginResult $prevResult) {

            if ($prevResult->error) {
                return $prevResult;
            }

            if (!$request->getQuery('oneString')) {
                return $prevResult;
            }    
            
            $arReturn = $this->cache->get('OneStringPlugin', $request);

            if ($arReturn === null) {
                $objects = array();
                $query = $request->getQuery('query');
                
                //разбиваем строку запроса на слова
                $arWords = preg_split('/(\ |\.|\;|\,)+/', $query, -1, PREG_SPLIT_NO_EMPTY);
                
                //нормализуем
                foreach ($arWords as $key => $word)
                {
                    $arWords[$key] = Tools::Normalize($word);
                }
                
                for ($i=0; $i<count($arWords); $i++)
                {
                    if ($i === count($arWords)-1 || mb_strlen($arWords[$i], mb_detect_encoding($arWords[$i])) <= 2)
                    {
                        $arWords[$i] = $arWords[$i] . '*';
                    }            
                }
                
                $houseForMongo = null; //строка для поиска номера дома в монго 
                if (preg_match('/\d+/', end($arWords)))
                {
                    $houseForMongo = array_pop($arWords);
                    $houseForMongo = str_replace('*', '', $houseForMongo);
                }               
                
                $searchString = implode(" ", $arWords);
                $sphinxClient = $this->sphinxClient;               
                
                $limit = $request->getQuery('limit') ? ((int) $request->getQuery('limit') >= 100 ? 100 : (int) $request->getQuery('limit')) : 100;
                $sphinxClient->SetLimits(0, $limit);
                
                $sphinxClient->SetMatchMode(SPH_MATCH_EXTENDED2);
                
                $sphinxClient->SetSortMode(SPH_SORT_ATTR_ASC, 'sort');
                
                $regionForSphinx = (string)$request->getQuery('regionId');
                $districtForSphinx = (string)$request->getQuery('districtId');
                $cityForSphinx = (string)$request->getQuery('cityId');

                $sphinxRes = null;
                
                if ($cityForSphinx)
                {
                    $sphinxRes = $sphinxClient->Query("@fullname $searchString @cityid $cityForSphinx");
                }
                elseif ($districtForSphinx)
                {
                    $sphinxRes = $sphinxClient->Query("@fullname $searchString @districtid $districtForSphinx");
                }
                elseif ($regionForSphinx)
                {
                    $sphinxRes = $sphinxClient->Query("@fullname $searchString @regionid $regionForSphinx");
                }
                else
                {
                    $sphinxRes = $sphinxClient->Query("@fullname $searchString");
                }
                
                if ($sphinxRes === false)
                {
                    $result = $prevResult;
                    $result->terminate = true;
                    $result->error = true;
                    $result->errorMessage = $sphinxClient->GetLastError();

                    return $result;
                }
                else
                {
                    if (empty($sphinxRes['matches'])) //если ничего не найдено - пытаемся убрать одно слово из запроса.
                    {
                        array_pop($arWords);
                        $searchString = implode(" ", $arWords);
                        $sphinxRes = $sphinxClient->Query($searchString);//подумать о повторном запросе при разных заданных областях
                    }                 
                    
                    if (!empty($sphinxRes['matches']))
                    {   
                        $sphinxIds = array();
                        $sphinxIds = array_keys($sphinxRes['matches']);
                        
                        foreach ($sphinxIds as &$id)
                        {
                            $id = (string)$id;
                        }
                        
                        $objects = Complex::find(array(
                            array(
                                'Id' => array( 
                                    '$in' => $sphinxIds                                    
                                    ))));
                    }
                }
                
                if ($houseForMongo) //ищем заданные дома в монго и заменяем часть элементов в массиве результатов
                {
                    $streets = array();
                    foreach ($objects as $object)
                    {
                        if ($object->readAttribute(KladrFields::ContentType) == 'street')   
                        {
                            $streets[] = $object;
                        }
                    }
                    
                    if (count($streets) > 0) //если найдена какая-то улица
                    {
                        $retBuildings = array();
                        $mainBuilding = null;
                        
                        foreach ($streets as $street)
                        {
                            $buildingsOfStr = Complex::find(array(
                                array(
                                    KladrFields::StreetId => $street->readAttribute(KladrFields::StreetId),
                                    KladrFields::ContentType => 'building'
                                )));

                            foreach ($buildingsOfStr as $buildingOfStr) //то начинаем искать дома до половины лимита запроса
                            {                           
                                foreach ($buildingOfStr->readAttribute(KladrFields::NormalizedBuildingName) as $buildName)
                                {
                                    if ($buildName === $houseForMongo)
                                    {
                                        $mainBuilding = $buildingOfStr; //находим точное совпадение
                                        $mainBuilding->NormalizedBuildingName = $buildName;
                                    }

                                    $reg = '/^' . $houseForMongo . '/';

                                    $match = preg_match($reg, $buildName) ? $buildName : null;

                                    //убираем длинные строки из домов
                                    $match = preg_match('/\,/', $match) ? null : $match;

                                    if ($match)
                                    {
                                        $building = clone $buildingOfStr;
                                        $building->NormalizedBuildingName = $match;                                   
                                        $retBuildings[] = $building;
                                    }
                                }
                            }

                            //убираем повторное точное вхождение, ставим его на первое место
                            if ($mainBuilding)
                            {
                                foreach ($retBuildings as $key => $retBuilding)
                                {
                                    if ($mainBuilding->NormalizedBuildingName == $retBuilding->NormalizedBuildingName)
                                    {
                                        unset($retBuildings[$key]);
                                    }
                                }

                                $retBuildings = array_merge(array($mainBuilding), $retBuildings);
                            }
                            
                            if (count($objects) > floor($limit/2))
                            {
                                if (count($retBuildings) >= ceil($limit/2))
                                {
                                    break;
                                }
                            }
                            elseif (count($retBuildings) >= $limit-count($objects))
                            {
                                break;
                            }
                        }
                        //заполянем лимит по максимуму
                        if (count($objects) > floor($limit/2))
                        {
                            if ($retBuildings > ceil($limit/2))
                            {
                                $retBuildings = array_slice($retBuildings, 0, ceil($limit/2));
                            }
                        }
                        else
                        {
                            $retBuildings = array_slice($retBuildings, 0, $limit-count($objects));
                        }

                        //сливаем массивы домов и остальных совпадений
                        $objects = array_merge($retBuildings, $objects);

                        //финальная обрезка массива
                        if ($objects > $limit)
                        {
                            $objects = array_slice($objects, 0, $limit, true);
                        }
                        
                    }//
                }

                foreach ($objects as $object) {
                    if ($object)
                    {    
                        $retObj = array(
                            'id' => $object->readAttribute(KladrFields::Id),
                            'name' => $object->readAttribute(KladrFields::Name),
                            'zip' => $object->readAttribute(KladrFields::ZipCode),
                            'type' => $object->readAttribute(KladrFields::Type),
                            'typeShort' => $object->readAttribute(KladrFields::TypeShort),
                            'okato' => $object->readAttribute(KladrFields::Okato),                       
                            'contentType' => $object->readAttribute(KladrFields::ContentType),
                            'fullName' => $object->readAttribute(KladrFields::FullName),  
                            'regionId' => $object->readAttribute(KladrFields::RegionId)
                        );                                       

                        switch ($retObj['contentType'])
                        {
                            case 'district':
                                $retObj['districtId'] = $object->readAttribute(KladrFields::DistrictId);
                                break;

                            case 'city':
                                $retObj['districtId'] = $object->readAttribute(KladrFields::DistrictId);
                                $retObj['cityId'] = $object->readAttribute(KladrFields::CityId);
                                break;

                            case 'street':
                                $retObj['districtId'] = $object->readAttribute(KladrFields::DistrictId);
                                $retObj['cityId'] = $object->readAttribute(KladrFields::CityId);
                                $retObj['streetId'] = $object->readAttribute(KladrFields::StreetId);
                                break;

                            case 'building':
                                $retObj['districtId'] = $object->readAttribute(KladrFields::DistrictId);
                                $retObj['cityId'] = $object->readAttribute(KladrFields::CityId);
                                $retObj['streetId'] = $object->readAttribute(KladrFields::StreetId);
                                $retObj['buildingId'] = $object->readAttribute(KladrFields::BuildingId);
                                break;

                            default:
                                break;
                        }
                    }
                    
                    if ($retObj['contentType'] == 'building')
                    {
                        $name = $object->readAttribute(KladrFields::TypeShort) . '. ' . ($object->readAttribute(KladrFields::NormalizedBuildingName));
                        $retObj['fullName'] .= ', ' . $name;
                        $retObj['name'] = $name;
                        $arReturn[] = $retObj;                                   
                    }
                    else
                    {
                        $arReturn[] = $retObj;  
                    }
                }            
                $this->cache->set('OneStringPlugin', $request, $arReturn);
            } 

            $result = $prevResult;
            $result->result = $arReturn;
            $result->terminate = true;

            return $result;
        }
        
        /*
         * Производит анализ массива поисковых слов, заполняет массив для поиска в БД
         */
        public function analysis(array $words, array &$searchArray)
        {
            //массивы для сравнения с различными типами объектов. в будущем просмотреть все возможные типы через цикл из БД
            $regionPrefixArr = array('республика', 'респ', 'р');
            $cityPrefixArr = array('г', 'город', 'территория', 'тер', 'улус', 'у', 'волость', 'дп', 'кп', 'пгт', 'по', 'рп', 'са', 'стер', 'со', 'смо', 'спос', 'сс', 'сельсовет', 'аал', 'аул', 'высел', 'городок', 'д', 'деревня', 'оп', 'будка', 'казарм', 'казарма', 'платф', 'ст', 'пост', 'заимка', 'микрорайон', 'мкр', 'нп', 'остров', 'пр', 'пст', 'п', 'посёлок', 'поселок', 'починок', 'по', 'промзона', 'рп', 'рзд', 'с', 'село', 'сл', 'слобода', 'ст-ца', 'х', 'высел', 'выселок', 'кв-л', 'квартал', 'местечко', 'м', 'пр', 'полуст', 'полустанок');
            $streetPrefixArr = array('улица', 'ул', 'проспект', 'пр', 'просп', 'аллея', 'бр', 'бульвар', 'въезд', 'дорога', 'дор', 'рзд', 'разъезд', 'заезд', 'км', 'километр', 'наб', 'набережная', 'городок','парк', 'переезд', 'д', 'деревня', 'переулок', 'пер', 'площадка','оп', 'будка', 'казарм', 'казарма', 'платф', 'ст', 'пл-ка', 'проезд', 'просек', 'пост', 'проселок', 'проулок', 'сад', 'сквер', 'стр', 'мкр', 'микрорайон', 'строение', 'тракт', 'туп', 'тупик', 'п', 'уч-к', 'ш', 'пр', 'м', 'местечко', 'кв-л', 'квартал', 'рзд', 'жт', 'высел', 'выселок', 'х', 'сл', 'слобода', 'с', 'село');
            $buildPrefixArr = array('д', 'дом');
            $districtSuffixArr = array('район', 'р', 'рн');
            $regionSuffixArr = array('область', 'обл', 'об', 'край', 'кр' , 'ао');//поля "автономный округ" и "автономная область" вычеркнуты
            
            $prevWord = '';
            
            $continue = false;
                      
            foreach ($words as &$word)
            {    
                if ($continue) 
                {
                    $continue = false;
                    continue;
                }

                if (!$searchArray[KladrFields::NormalizedRegionName])
                {
                    if (in_array($word, $regionPrefixArr))
                    { 
                        $this->regionPrefixFound(current($words), $searchArray); 
                        //$regionWasFound = true;
                        $continue = true;
                        continue;
                    }
                    elseif (in_array($word, $regionSuffixArr))
                    {
                        $this->regionSuffixFound($prevWord, $searchArray);
                        //$regionWasFound = true;
                        continue;
                    }
                }
                
                if (!$searchArray[KladrFields::NormalizedDistrictName])
                {
                    if (in_array($word, $districtSuffixArr))
                    {
                        $this->districtSuffixFound($prevWord, $searchArray);
                        //$districtWasFound = true;
                        continue;                      
                    }
                }
                
                if (!$searchArray[KladrFields::NormalizedCityName])
                {
                    if (in_array($word, $cityPrefixArr))
                    {
                        $this->cityPrefixFound(current($words), $searchArray);
                        $continue = true;
                        //$cityWasFound = true;
                        continue;
                    }
                }
                
                if (!$searchArray[KladrFields::NormalizedStreetName])
                {
                    if (in_array($word, $streetPrefixArr))
                    {
                        $this->streetPrefixFound(current($words), $searchArray);
                        $continue = true;
                        //$streetWasFound = true;
                        continue;
                    }
                }
                
                if (!$searchArray[KladrFields::NormalizedBuildingName])
                {
                    if (in_array($word, $buildPrefixArr))
                    {
                        $this->buildPrefixFound(current($words), $searchArray);
                        $continue = true;
                        //$buildWasFound = true;
                        continue;
                    }
                }
                               
                $this->anotherWordFound($word, $searchArray);
                $prevWord = $word;           
            }
        }

        /*
         * Обработчик республики в массиве для поиска
         */
        public function regionPrefixFound($word, array &$searchArray)
        {
            $searchArray['conditions'][KladrFields::NormalizedRegionName] = $word;
            $searchArray['conditions'][KladrFields::Address]['$all'][] = $word;   
        }
        
        /*
         * Обработчик города в массиве для поиска
         */
        public function cityPrefixFound($word, array &$searchArray)
        {
             $searchArray['conditions'][KladrFields::NormalizedCityName] = $word;
             $searchArray['conditions'][KladrFields::Address]['$all'][] = $word;   
        }
        
        /*
         * Обработчик улицы в массиве для поиска
         */
        public function streetPrefixFound($word, array &$searchArray)
        {
              $searchArray['conditions'][KladrFields::NormalizedStreetName] = $word;
              $searchArray['conditions'][KladrFields::Address]['$all'][] = $word;   
        }
        
        /*
         * Обработчик дома в массиве для поиска
         */
        public function buildPrefixFound($word, array &$searchArray)
        {
            $searchArray['conditions'][KladrFields::NormalizedBuildingName] = $word;    
            $searchArray['conditions'][KladrFields::Address]['$all'][] = $word;
        }
        
        /*
         * Обработчик района в массиве для поиска
         */
        public function districtSuffixFound($word, array &$searchArray)
        {
            $searchArray['conditions'][KladrFields::NormalizedDistrictName] = $word;      
        }
        
        /*
         * Обработчик района в массиве для поиска
         */
        public function regionSuffixFound($word, array &$searchArray)
        {         
            //область и край
            $searchArray['conditions'][KladrFields::NormalizedRegionName] = $word;            
        }       

        /*
         * Обработчик слова, не попавшего под условия в массиве для поиска
         */
        public function anotherWordFound($word, array &$searchArray)
        {
            $searchArray['conditions'][KladrFields::Address]['$all'][] = $word;           
        }
               
        /*
         * Выполняет поиск по базе данных. Возвращает найденные значения
         */
        public function search(array &$searchArray)
        {
            if ($searchArray['conditions'] != null)
            {                              
                switch (end($searchArray['conditions'][KladrFields::Address]['$all']))
                {
                    case $searchArray['conditions'][KladrFields::NormalizedRegionName]:                       
                        $searchArray['conditions'][KladrFields::NormalizedRegionName] = new \MongoRegex('/^' . $searchArray['conditions'][KladrFields::NormalizedRegionName] . '/');
                        break;

                    case $searchArray['conditions'][KladrFields::NormalizedDistrictName]:
                        $searchArray['conditions'][KladrFields::NormalizedDistrictName] = new \MongoRegex('/^' . $searchArray['conditions'][KladrFields::NormalizedDistrictName] . '/');
                        break;

                    case $searchArray['conditions'][KladrFields::NormalizedCityName]:
                        $searchArray['conditions'][KladrFields::NormalizedCityName] = new \MongoRegex('/^' . $searchArray['conditions'][KladrFields::NormalizedCityName] . '/');
                        break;

                    case $searchArray['conditions'][KladrFields::NormalizedStreetName]:
                        $searchArray['conditions'][KladrFields::NormalizedStreetName] = new \MongoRegex('/^' . $searchArray['conditions'][KladrFields::NormalizedStreetName] . '/');
                        break;
                   
                    case $searchArray['conditions'][KladrFields::NormalizedBuildingName]:
                        $searchArray['conditions'][KladrFields::NormalizedBuildingName] = new \MongoRegex('/^' . $searchArray['conditions'][KladrFields::NormalizedBuildingName] . '/');
                        break;
                }
                reset($searchArray['conditions'][KladrFields::Address]['$all']);
                $willReg = array_pop($searchArray['conditions'][KladrFields::Address]['$all']);
                $searchArray['conditions'][KladrFields::Address]['$all'][] = new \MongoRegex('/^' . $willReg . '/');
               
                return Complex::find($searchArray);
           }
           else return null;
        }
        
    }       
}



