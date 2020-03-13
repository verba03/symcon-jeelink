<?
    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);


class BME280 extends IPSModule {
/**
 * Taupunktberechnung in °C
 * @param float $temperatur
 * @param int $relhumidity
 * @return float
 */
private function Dewpoint(float $temperatur, int $relhumidity) {
    $val = (234.67 * 0.434292289 * log(6.1 * exp((7.45 * $temperatur) / (234.67 + $temperatur) * 2.3025851) * $relhumidity / 100 / 6.1)) / (7.45 - 0.434292289 * log(6.1 * exp((7.45 * $temperatur) / (234.67 + $temperatur) * 2.3025851) * $relhumidity / 100 / 6.1));

    return number_format($val, 1);
}

/**
 * Sättigungsdampfdruck in hPa
 * @param float $temperatur
 * @return float
 */
private function SaettigungsDampfDruck(float $temperatur) {
    $a = 0;
    $b = 0;
    if ($temperatur >= 0) {
        $a = 7.5;
        $b = 237.3;
    }
    elseif ($temperatur < 0) {
        $a = 7.6;
        $b = 240.7;
    }
    $val = (6.1078 * exp(log(10) * (($a * $temperatur) / ($b + $temperatur))));
    return $val;
}

/**
 * Dampfdruck in hPa
 * @param float $temperatur
 * @param int $relfeuchte
 * @return float
 */
private function Dampfdruck(float $temperatur, int $relfeuchte) {
    $val = $relfeuchte / 100 * $this->SaettigungsDampfDruck($temperatur);
    return $val;
}

/**
 * absolute Feuchte in g/m³
 * @param float $temperatur
 * @param int $relfeuchte
 * @return float
 */
private function AbsoluteFeuchte(float $temperatur, int $relfeuchte) {
    $tk  = ($temperatur + 273.15);
    $val = (exp(log(10) * 5) * 18.016 / 8314.3 * $this->DampfDruck($temperatur, $relfeuchte) / $tk);
    return number_format($val, 2);
}
    public function Create() {

        // Diese Zeile nicht löschen oder ändern.
        parent::Create();
        //Erstelle und Verbinde mit Cutter
        $this->ConnectParent("{AC6C6E74-C797-40B3-BA82-F135D941D1A2}");
        $this->RegisterPropertyInteger("SensorID", 0);
        $this->RegisterVariableFloat("TEMPERATUR", "Temperatur", "~Temperature",0);
        $this->RegisterVariableInteger("PRESSURE", "Pressure", "~AirPressure",0);
        $this->RegisterVariableInteger("HUMIDITY", "Luftfeuchtigkeit", "~Humidity",0);
        $this->RegisterVariableFloat("DEWPOINT", "Taupunkt", "~Temperature",0);
        $this->RegisterVariableFloat("ABSHUM", "Abs. Luftfeuchtigkeit", "",0);
    }

    /**
     *
     */
    public function ApplyChanges() {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        //Never delete this line!
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() <> KR_READY) {
            return;
        }
        $id     = $this->ReadPropertyInteger('SensorID');
        $this->SetSummary("Sensor ID : ".$id);
        $Filter = '.*OK WS '. $id . '.*';
        $this->SetReceiveDataFilter($Filter);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        switch ($Message) {
            case IPS_KERNELSTARTED: // Nach dem IPS-Start
                $this->KernelReady(); // Sagt alles.
                break;
        }
    }

    protected function KernelReady() {
        $this->ApplyChanges();
    }

    /**
     * @param $JSONString
     */
    public function ReceiveData($JSONString) {
        /* @var $data array */
        $data  = json_decode($JSONString);
        $this->SendDebug("Received data: ", utf8_decode($data->Buffer), 0);
        // OK WS 0  2   4  212 255 255 255 255 255 255 255 255 255  0   3  241    ID=0 T:23,6 Druck 1009 hPa
        // OK WS 0 XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX XXX
        // |  |  |  |   |   |   |   |   |   |   |   |   |   |   |   |   |   | --- [17] Druck LSB
        // |  |  |  |   |   |   |   |   |   |   |   |   |   |   |   |   |-------- [16] Druck MSB
        // |  |  |  |   |   |   |   |   |   |   |   |   |   |   | --------------- [15]
        // |  |  |  |   |   |   |   |   |   |   |   |   |   |-------------------- [14]
        // |  |  |  |   |   |   |   |   |   |   |   |   |   |-------------------- [13]
        // |  |  |  |   |   |   |   |   |   |   |   |   |------------------------ [12]
        // |  |  |  |   |   |   |   |   |   |   |   |---------------------------- [11]
        // |  |  |  |   |   |   |   |   |   |   |-------------------------------- [10]
        // |  |  |  |   |   |   |   |   |   |------------------------------------ [9]
        // |  |  |  |   |   |   |   |   |---------------------------------------- [8]
        // |  |  |  |   |   |   |   |-------------------------------------------- [7]
        // |  |  |  |   |   |   |------------------------------------------------ [6]Hummidity (0..100)
        // |  |  |  |   |   |---------------------------------------------------- [5]Temp * 10 + 1000 LSB
        // |  |  |  |   |-------------------------------------------------------- [4]Temp * 10 + 1000 MSB
        // |  |  |  |------------------------------------------------------------ [3]Sensor type 2 fix
        // |  |  |--------------------------------------------------------------- [2]Sensor ID
        // |  |------------------------------------------------------------------ [1]fix "WS"
        // |--------------------------------------------------------------------- [0]fix "OK"
        //Parse and write values to our variables
        /* @var $bytes array */
        $bytes = explode(' ', $data->Buffer);
        if ($bytes[0] == 'OK' and $bytes[1] == 'WS') {
            $temperature = ($bytes[4] * 256 + $bytes[5] - 1000) / 10;
            $humidity = $bytes[6];
            $pressure    = ($bytes[16] * 256) + $bytes[17];
            // nur definierte Sensoren behandeln
            if ($bytes[2] == $this->ReadPropertyInteger("SensorID")) {
               
                $old_temp = GetValueFloat($this->GetIDForIdent("TEMPERATUR"));
                $old_hum = GetValueInteger($this->GetIDForIdent("HUMIDITY"));
                //$this->SendDebug('OLD_TEMP',$old_temp,0);
                //$this->SendDebug('NEW_TEMP',$temperature,0);
                if ($humidity != $old_hum) {
                    if (($humidity <= $old_hum + 5) || ($humidity >= $old_hum - 5)) {
                        SetValueFloat($this->GetIDForIdent("TEMPERATUR"), $temperature);
                        SetValueInteger($this->GetIDForIdent("HUMIDITY"), $humidity);
                        $dewpoint = $this->Dewpoint($temperature, $humidity);
                        SetValueFloat($this->GetIDForIdent("DEWPOINT"), $dewpoint);
                        $abshum   = $this->AbsoluteFeuchte($temperature, $humidity);
                        SetValueFloat($this->GetIDForIdent("ABSHUM"), $abshum);
                        SetValueInteger($this->GetIDForIdent("PRESSURE"), $pressure);
                    }
                }
                if ($temperature != $old_temp) {
                    if (($temperature <= $old_temp + 5.0) || ($temperature >= $old_temp - 5.0)) {
                        SetValueFloat($this->GetIDForIdent("TEMPERATUR"), $temperature);
                        SetValueInteger($this->GetIDForIdent("HUMIDITY"), $humidity);
                        $dewpoint = $this->Dewpoint($temperature, $humidity);
                        SetValueFloat($this->GetIDForIdent("DEWPOINT"), $dewpoint);
                        $abshum   = $this->AbsoluteFeuchte($temperature, $humidity);
                        SetValueFloat($this->GetIDForIdent("ABSHUM"), $abshum);
                        SetValueInteger($this->GetIDForIdent("PRESSURE"), $pressure);
                    }
                }
            }
        } //if
    } //function
} //class
?>