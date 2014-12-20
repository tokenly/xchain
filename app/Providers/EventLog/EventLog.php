<?php

namespace App\Providers\EventLog;


use RuntimeException;
use Exception;
use Illuminate\Support\Facades\Log;

class EventLog {

    public function __construct() {
        $this->influxdb_client = new \crodas\InfluxPHP\Client(
           "localhost",
           8086,
           "root",
           "root"
        );
        $this->influxdb = $this->influxdb_client->xchain_logs;
    }

    // statsd methods
    public function count($metric, $value) {
        $this->log($metric, ['action' => 'count', 'value' => $value], null, ['value' => $value]);
    } 
    public function guage($metric, $value) {
        $this->log($metric, ['action' => 'guage', 'value' => $value], null, ['value' => $value]);
    } 
    public function increment($metric) {
        $this->log($metric, ['action' => 'increment']);
    } 

    public function log($event, $raw_data, $array_keys_only=null, $other_columns=null) {
        try {
            if ($array_keys_only) {
                $data = [];
                foreach($array_keys_only as $array_key) {
                    $data[$array_key] = $raw_data[$array_key];
                }
            } else {
                $data = $raw_data;
            }

            $row = ['event' => $event, 'data' => json_encode($data)];
            if ($other_columns !== null) {
                $row = array_merge($row, $other_columns);
            }
            $this->influxdb->insert('events', $row);

            // write to laravel log
            Log::debug('event:'.$event." ".str_replace('\n', "\n", json_encode($data, 192)));
        } catch (RuntimeException $e) {
            // influxdb probably not connected...
            Log::error("RuntimeException in ".$e->getFile()." at line ".$e->getLine());
        } catch (Exception $e) {
            // other error
            Log::error($e->getCode()." ".$e->getMessage()." in ".$e->getFile()." at line ".$e->getLine());
        }
    }

    public function logError($event, $error_or_data, $additional_error_data=null) {
        if ($error_or_data instanceof Exception) {
            $e = $error_or_data;
            $raw_data = [
                'error' => $e->getMessage(),
                'code'  => $e->getCode(),
                'line'  => $e->getLine(),
                'file'  => $e->getFile(),
            ];
        } else {
            $raw_data = $error_or_data;
        }

        // merge extra data
        if ($additional_error_data !== null) {
            $raw_data = array_merge($raw_data, $additional_error_data);
        }

        $this->log($event, $raw_data);
    }


}
