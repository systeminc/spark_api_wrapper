<?php

/**
 * Spark API v2 standalone wrapper
 * @author Mladen Janjetovic <mladen@systemstudio.co>
 */

class SparkAPI
{
    private static $api_key = "YOUR_SPARK_API_KEY";

    /**
     * Make GET API call
     *
     * @param string $uri
     * @param int|null $page pagination page number
     * @return mixed
     */
    private static function get(string $uri, int $page = null)
    {
        $curl = curl_init();
        $pagination = (strpos($uri, '?') === false ? '?' : '&') . 'per_page=100' . ($page ? '&page=' . $page : '');

        $curlArgs = array(
            CURLOPT_URL            => 'https://api.spark.re/v2/' . $uri . $pagination,
            CURLOPT_CUSTOMREQUEST  => "GET",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Token token="' . self::$api_key . '"',
                'Content-Type: application/json'
            )
        );

        curl_setopt_array($curl, $curlArgs);
        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode($response, true);
    }

    /**
     * Make POST API call
     *
     * @param $uri
     * @param array $post_data
     * @return array
     */
    private static function post(string $uri, array $post_data)
    {
        $curl = curl_init();

        $curlArgs = array(
            CURLOPT_URL            => 'https://api.spark.re/v2/' . $uri,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_POSTFIELDS     => json_encode($post_data),
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Token token="' . self::$api_key . '"',
                'Content-Type: application/json'
            ),
        );

        curl_setopt_array($curl, $curlArgs);
        $response = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return [
            'status' => $http_code,
            'data'   => json_decode($response, true),
        ];
    }

    /**
     * Get all Inventory units
     *
     * @return array|false
     */
    public static function getUnits()
    {
        $return = [];
        $page_number = 1;

        while ($new_page = self::get('inventory', $page_number)) {
            $return = array_merge($return, $new_page);
            $page_number++;
        }

        return empty($return) ? $return : array_combine(array_column($return, 'id'), $return);
    }

    /**
     * Populate Units with a floor plan
     *
     * @param array $units
     * @return true
     */
    public static function populateUnitsFloorplans(array &$units)
    {
        $return = [];
        $page_number = 1;

        while ($new_page = self::get('floorplans', $page_number)) {
            $return = array_merge($return, $new_page);
            $page_number++;
        }

        $floorplans = array_combine(array_column($return, 'id'), $return);

        foreach ($units as &$unit) {
            $unit['floorplan'] = $floorplans[$unit['floorplan_id']] ?? null;
        }

        return true;
    }

    /**
     * Populate Units with a Status object
     *
     * @param array $units
     * @return true
     */
    public static function populateUnitsStatuses(array &$units)
    {
        $return = [];
        $page_number = 1;

        while ($new_page = self::get('inventory-statuses', $page_number)) {
            $return = array_merge($return, $new_page);
            $page_number++;
        }

        $statuses = array_combine(array_column($return, 'id'), $return);

        foreach ($units as &$unit) {
            $unit['status'] = $statuses[$unit['status_id']] ?? null;
        }

        return true;
    }

    /**
     * Populate Units with Additional Fields
     *
     * @param array $units
     * @return true
     */
    public static function populateUnitsAdditionalFields(array &$units)
    {
        $return = [];
        $page_number = 1;

        while ($new_page = self::get('additional-fields?inventory_id_not_null=true', $page_number)) {
            $return = array_merge($return, $new_page);
            $page_number++;
        }

        $fields = array_combine(array_column($return, 'id'), $return);

        foreach ($fields as $field) {
            if (isset($field['inventory_id']) && isset($units[$field['inventory_id']])) {
                $name = strtolower(str_replace(' ', '_', $field['name']));
                $units[$field['inventory_id']][$name] = $field['value'];
            }
        }

        return true;
    }

    /**
     * Get Brokerage by name
     *
     * @param string $name
     * @return mixed
     * @throws Exception
     */
    public static function getBrokerage(string $name)
    {
        $exists = self::get('brokerages?name_eq=' . $name, false);

        if ($exists) {
            $brokerage = $exists[0];
        } else {
            $create = self::post('brokerages', ['name' => $name]);

            if ($create['status'] == 201) {
                $brokerage = $create['data'];
            } else {
                throw new Exception($create['message']);
            }
        }

        return $brokerage;
    }

    /**
     * Send Contact to API
     *
     * @param array $data
     * @return array{status:int,message:string}|string[]
     */
    public static function postContact(array $data)
    {
        $response = self::post('contacts', $data);

        if ($response['status'] == 201) {
            return [
                'status'  => 'success',
                'message' => '',
            ];
        } else {
            return [
                'status'  => 'failed',
                'message' => $response['data']['error_message'],
            ];
        }
    }

    /**
     * Get all Units with Floor plan, Status and Additional Fields
     *
     * @return array|false
     */
    public static function getUnitsWithDetails()
    {
        $units = SparkAPI::getUnits();

        SparkAPI::populateUnitsFloorplans($units);
        SparkAPI::populateUnitsStatuses($units);
        SparkAPI::populateUnitsAdditionalFields($units);

        return $units;
    }
}
