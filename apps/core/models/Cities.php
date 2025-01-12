<?php

namespace Kladr\Core\Models {

    use \Phalcon\Mvc\Collection;

    /**
     * Kladr\Core\Models\Cities
     * 
     * Коллекция населённых пунктов
     * 
     * @property string $Id Идентификатор
     * @property string $Name Название
     * @property string $NormalizedName Нормализованное название
     * @property string $ZipCode Почтовый индекс
     * @property string $Type Подпись
     * @property string $TypeShort Подпись коротко
     * @property string $Okato ОКАТО
     * @property int $CodeRegion Код региона
     * @property int $CodeDistrict Код района
     * @property int $CodeCity Код населённого пункта
     * @property int $Sort Сортировка
     * 
     * @author A. Yakovlev. Primepix (http://primepix.ru/)
     */
    class Cities extends Collection
    {
        /**
         * @var string Тип объекта
         */
        const ContentType = "city";

        /**
         * Кеш, чтоб снизить запросы к базе
         * @var array
         */
        private static $Cache = array();

        public function getSource()
        {
            return "cities";
        }

        /**
         * Возвращает массив кодов текущего объекта
         * 
         * @param string $id ID
         * @return array
         */
        public static function getCodes($id)
        {
            if (isset(self::$Cache[$id]))
                return self::$Cache[$id];

            $object = self::findFirst(array(
                        array(KladrFields::Id => $id)
            ));

            if (!$object)
                return array();

            self::$Cache[$id] = array(
                KladrFields::CodeRegion => $object->readAttribute(KladrFields::CodeRegion),
                KladrFields::CodeDistrict => $object->readAttribute(KladrFields::CodeDistrict),
                KladrFields::CodeLocality => $object->readAttribute(KladrFields::CodeLocality),
            );

            return self::$Cache[$id];
        }

        /**
         * Поиск объекта по названию
         * 
         * @param string $name Название объекта
         * @param array $codes Коды родительского объекта
         * @param int $limit Максимальное количество возвращаемых объектов
         * @param int $offset Сдвиг
         * @param array $typeCodes Массив TypeCode для фильтрации
         * @return array
         */
        public static function findByQuery($name = null, $codes = array(), $limit = 5000, $offset = 0, $typeCodes = null)
        {
            $arQuery = array();
            $isEmptyQuery = true;

            if ($name)
            {
                $isEmptyQuery = false;
                $regexObj = new \MongoRegex('/^' . $name . '/');
                $arQuery['conditions'][KladrFields::NormalizedName] = $regexObj;
            }

            $searchById = $codes && !is_array($codes);

            if (is_array($codes))
            {
                $isEmptyQuery = false;
                $codes = array_splice($codes, 0, 3);
                foreach ($codes as $field => $code)
                {
                    if ($code)
                    {
                        $arQuery['conditions'][$field] = $code;
                    }
                    else
                    {
                        $arQuery['conditions'][$field] = null;
                    }
                }
            }
            elseif ($searchById)
            {
                $isEmptyQuery = false;
                $arQuery['conditions'][KladrFields::Id] = $codes;
            }

            if ($isEmptyQuery)
            {
                return array();
            }

            if (!$searchById)
            {
                $arQuery['conditions'][KladrFields::Bad] = false;
            }

            if($typeCodes != null)
            {
                $arQuery['conditions'][KladrFields::TypeCode] = array('$in' => $typeCodes);
            }
            
            $arQuery['sort'] = array(KladrFields::Sort => 1);

            $arQuery['skip'] = $offset;
            $arQuery['limit'] = $limit;
//            $arQuery['limit'] = 4;
            

            $cities = self::find($arQuery);

            $arReturn = array();           
            foreach($cities as $city)
            {
                $arReturn[] = array(
                    'id'          => $city->readAttribute(KladrFields::Id),
                    'name'        => $city->readAttribute(KladrFields::Name),
                    'zip'         => $city->readAttribute(KladrFields::ZipCode),
                    'type'        => $city->readAttribute(KladrFields::Type),
                    'typeShort'   => $city->readAttribute(KladrFields::TypeShort),
                    'okato'       => $city->readAttribute(KladrFields::Okato),
                    'contentType' => Cities::ContentType,
                );
            }

            return $arReturn;
        }

    }

}