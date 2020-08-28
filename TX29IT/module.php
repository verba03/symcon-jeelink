<?

    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);


class TX29IT extends IPSModule {

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
        $this->RegisterVariableBoolean("BATTERY", "Batterie Sensor", "~Battery",0);
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
        /* @var $cutterID int */
        //$cutterID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        //if (IPS_GetName($cutterID) != 'Jeelink Cutter') {
        //    IPS_SetName($cutterID, "Jeelink Cutter");
        //    IPS_SetProperty($cutterID, "RightCutChar", "\r\n");
        //    IPS_ApplyChanges($cutterID);
        //}
        /* @var $id int */
        $id     = $this->ReadPropertyInteger('SensorID');
        $this->SetSummary("Sensor ID : ".$id);
        $Filter = '.*OK 9 ' . $id . '.*';
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
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:
     *
     * IOT_Send($id, $text);
     *
     */
    public function Send(string $Text) {
        $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $Text)));
    }

    public function ReceiveData($JSONString) {
        /* @var $data array */
        $data  = json_decode($JSONString);
        $this->SendDebug("Received data: ", utf8_decode($data->Buffer), 0);
        //$sensorString = $this->ReadPropertyString("sensoren");
        // Decodierung
        # Temperature sensor - Format:
        #      0   1   2   3   4
        # -------------------------
        # OK 9 56  1   4   156 37     ID = 56  T: 18.0  H: 37  no NewBatt
        # OK 9 49  1   4   182 54     ID = 49  T: 20.6  H: 54  no NewBatt
        # OK 9 55  129 4   192 56     ID = 55  T: 21.6  H: 56  WITH NewBatt
        # OK 9 2   1   4 212 106       ID = 2   T: 23.6  H: -- Channel: 1
        # OK 9 2   130 4 225 125       ID = 2   T: 24.9  H: -- Channel: 2
        # OK 9 ID XXX XXX XXX XXX
        # |  | |  |   |   |   |
        # |  | |  |   |   |   --- Humidity incl. WeakBatteryFlag
        # |  | |  |   |   |------ Temp * 10 + 1000 LSB
        # |  | |  |   |---------- Temp * 10 + 1000 MSB
        # |  | |  |-------------- Sensor type (1 or 2) +128 if NewBatteryFlag
        # |  | |----------------- Sensor ID
        # |  |------------------- fix "9"
        # |---------------------- fix "OK"
        //Parse and write values to our variables
        /* @var $bytes array */
        $bytes = explode(' ', $data->Buffer);
        if ($bytes[0] == 'OK' and $bytes[1] == '9') {
            /* @var $addr int */
            $addr        = $bytes[2];
            /* @var $battery_new bool */
            $battery_new = ($bytes[3] & 0x80) >> 7;
            /* @var $battery_low bool */
            $battery_low = ($bytes[6] & 0x80) >> 7;
            /* @var $type int */
            $type        = ($bytes[3] & 0x70) >> 4;
            /* @var $channel int */
            $channel     = $bytes[3] & 0x0F;
            /* @var $temperature float */
            $temperature = ($bytes[4] * 256 + $bytes[5] - 1000) / 10;
            /* @var $humidity int */
            $humidity    = $bytes[6] & 0x7f;
            // nur definierte Sensoren behandeln
            if ($addr == $this->ReadPropertyInteger("SensorID")) {
                $old_temp = $this->GetValue("TEMPERATUR");
                //$this->SendDebug ('OLD_TEMP', $old_temp, 0);
                //$this->SendDebug ('NEW_TEMP', $temperature, 0);
                $temperature = $this->Mittelwert($old_temp, $temperature);
                //$this->SendDebug ('NEW_TEMP_M', $temperature, 0);
                if ($temperature != $old_temp) {
                    if (($temperature <= ($old_temp + 5.0)) || ($temperature >= ($old_temp - 5.0))) {
                        $this->SetValue("TEMPERATUR", $temperature);
                    }
                }
                if ($battery_low != $this->GetValue("BATTERY")) {
                    $this->SetValue("BATTERY", $battery_low);
                }
            } //if
        } //if
    }

//function
}

//class
?>