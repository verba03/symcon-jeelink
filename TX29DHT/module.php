<?php
    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);
/**
 *
 */
class TX29DHT extends IPSModule {

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

    private function Mittelwert(float $diff_tur, float $diff_mittel) {
        $tau = 0.5; /* 1 0 keine mittlung; je kleiner umso stärker ist die mittelung */
        $diff =  $diff_tur - $diff_mittel;
        $diff_mittel = $diff_mittel + ($tau * $diff);
        return number_format($diff_mittel, 1); 
    }

    public function Create() {

        // Diese Zeile nicht löschen oder ändern.
        parent::Create();
        //Erstelle und Verbinde mit Cutter
        $this->ConnectParent("{AC6C6E74-C797-40B3-BA82-F135D941D1A2}");
        $this->RegisterPropertyInteger("SensorID", 0);
        //$this->RegisterVariableInteger("ID", "Sensor ID", "",0);
        $this->RegisterVariableFloat("TEMPERATUR", "Temperatur", "~Temperature",0);
        $this->RegisterVariableInteger("HUMIDITY", "Luftfeuchtigkeit", "~Humidity",0);
        $this->RegisterVariableFloat("DEWPOINT", "Taupunkt", "~Temperature",0);
        $this->RegisterVariableFloat("ABSHUM", "Abs. Luftfeuchtigkeit", "",0);
        $this->RegisterVariableBoolean("BATTERY", "Batterie Sensor", "~Battery",0);
    }

    public function ApplyChanges() {
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterMessage($this->InstanceID, IPS_INSTANCEMESSAGE);
        //Never delete this line!
        parent::ApplyChanges();
        if (IPS_GetKernelRunlevel() <> KR_READY) {
            return;
        }

        //$cutterID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        //if(IPS_GetName($cutterID) != 'Jeelink Cutter'){
        //    IPS_SetName($cutterID,"Jeelink Cutter");
        //    IPS_SetProperty($cutterID,"RightCutChar","\r\n");
        //    IPS_ApplyChanges($cutterID);
        //    }

        if (!IPS_VariableProfileExists("AbsHumidity")) {
            IPS_CreateVariableProfile("AbsHumidity", 2);
            IPS_SetVariableProfileDigits("AbsHumidity", 2);
            IPS_SetVariableProfileText("AbsHumidity", "", " g/m3");
        }
        IPS_SetVariableCustomProfile($this->GetIDForIdent("ABSHUM"), "AbsHumidity");
        $id     = $this->ReadPropertyInteger('SensorID');
        $this->SetSummary("Sensor ID : ".$id);
        $Filter = '.*OK 9 ' . $id . '.*';
        $this->SetReceiveDataFilter($Filter);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;
            case IM_CONNECT:
                $this->SetStatus(102);
                break;
            case IM_DISCONNECT:
                $this->SetStatus(104);
                break;
        }
    }

    /**
     *
     */
    protected function KernelReady() {
        $this->ApplyChanges();
    }

    /**
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:
     *
     * IOT_Send($id, $text);
     *
     */
    public function Send(string $Text) {
        $this->SendDataToParent(json_encode(array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $Text)));
    }

    public function ReceiveData($JSONString) {
        $data  = json_decode($JSONString);
        $this->SendDebug("Received data: ", utf8_decode($data->Buffer), 0);
        //$sensorString = $this->ReadPropertyString("sensoren");
        // Decodierung
        // Temperature sensor - Format:
        //      0   1   2   3   4
        // -------------------------
        // OK 9 56  1   4   156 37     ID = 56  T: 18.0  H: 37  no NewBatt
        // OK 9 49  1   4   182 54     ID = 49  T: 20.6  H: 54  no NewBatt
        // OK 9 55  129 4   192 56     ID = 55  T: 21.6  H: 56  WITH NewBatt
        // OK 9 2   1   4 212 106       ID = 2   T: 23.6  H: -- Channel: 1
        // OK 9 2   130 4 225 125       ID = 2   T: 24.9  H: -- Channel: 2
        // OK 9 ID XXX XXX XXX XXX
        // |  | |  |   |   |   |
        // |  | |  |   |   |   --- Humidity incl. WeakBatteryFlag
        // |  | |  |   |   |------ Temp * 10 + 1000 LSB
        // |  | |  |   |---------- Temp * 10 + 1000 MSB
        // |  | |  |-------------- Sensor type (1 or 2) +128 if NewBatteryFlag
        // |  | |----------------- Sensor ID
        // |  |------------------- fix "9"
        // |---------------------- fix "OK"
        //Parse and write values to our variables
        $bytes = explode(' ', $data->Buffer);
        if ($bytes[0] == 'OK' and $bytes[1] == '9') {
            $addr        = $bytes[2];
            //$battery_new = ($bytes[3] & 0x80) >> 7;
            $battery_low = ($bytes[6] & 0x80) >> 7;
            //$type = ($bytes[3] & 0x70) >> 4;
            //$channel = $bytes[3] & 0x0F;
            $temperature = ($bytes[4] * 256 + $bytes[5] - 1000) / 10;
            $humidity    = $bytes[6] & 0x7f;
            // nur definierte Sensoren behandeln
            if ($addr == $this->ReadPropertyInteger("SensorID")) {
                $old_temp = $this->GetValue("TEMPERATUR");
                $old_hum = $this->GetValue("HUMIDITY");
                //$this->SendDebug('OLD_TEMP',$old_temp,0);
                //$this->SendDebug('NEW_TEMP',$temperature,0);
                $temperature = $this->Mittelwert($old_temp, $temperature);
                if ($humidity != $old_hum ) {
                    $this->SetValue("TEMPERATURE", $temperature);
                    $this->SetValue("HUMIDITY", $humidity);
                    $dewpoint = $this->Dewpoint($temperature, $humidity);
                    $this->SetValue("DEWPOINT", $dewpoint);
                    $abshum = $this->AbsoluteFeuchte($temperature, $humidity);
                    $this->SetValue("ABSHUM", $abshum);
                }
                if ($temperature != $old_temp) {
                    if (($temperature <= $old_temp + 5.0) || ($temperature >= $old_temp - 5.0)) {
                        $this->SetValue("TEMPERATURE", $temperature);
                        $this->SetValue("HUMIDITY", $humidity);
                        $dewpoint = $this->Dewpoint($temperature, $humidity);
                        $this->SetValue("DEWPOINT", $dewpoint);
                        $abshum = $this->AbsoluteFeuchte($temperature, $humidity);
                        $this->SetValue("ABSHUM", $abshum);
                    }
                }
                if ($battery_low != $tis->GetValue("BATTERY")) {
                    $this->SetValue("BATTERY", $battery_low);
                }
            } //if
        } //if
    }

//function
}

//class
