<?

    define('vtBoolean', 0);
    define('vtInteger', 1);
    define('vtFloat', 2);
    define('vtString', 3);


class BMP180 extends IPSModule {

    public function Create() {

        // Diese Zeile nicht löschen oder ändern.
        parent::Create();
        //Erstelle und Verbinde mit Cutter
        $this->ConnectParent("{AC6C6E74-C797-40B3-BA82-F135D941D1A2}");
        $this->RegisterPropertyInteger("SensorID", 0);
        //$this->RegisterVariableInteger("ID", "Sensor ID", "",0);
        $this->RegisterVariableFloat("TEMPERATUR", "Temperatur", "~Temperature",0);
        $this->RegisterVariableInteger("PRESSURE", "Pressure", "~AirPressure",0);
        //$this->RegisterVariableBoolean("BATTERY", "Batterie Sensor", "~Battery",0);
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
        $cutterID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        /*
        if (IPS_GetName($cutterID) != 'Jeelink Cutter') {
            IPS_SetName($cutterID, "Jeelink Cutter");
            IPS_SetProperty($cutterID, "RightCutChar", "\r\n");
            IPS_ApplyChanges($cutterID);
        }
        */
        /* @var $id int */
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
     * This function will be available automatically after the module is imported with the module control.
     * Using the custom prefix this function will be callable from PHP and JSON-RPC through:
     *
     * IOT_Send($id, $text);
     *
     */
    /*
    public function Send(string $Text) {
        $this->SendDataToParent(json_encode(Array("DataID" => "{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}", "Buffer" => $Text)));
    } */

    public function ReceiveData($JSONString) {
        /* @var $data array */
        $data  = json_decode($JSONString);
        $this->SendDebug("Received data: ", utf8_decode($data->Buffer), 0);
        //$sensorString = $this->ReadPropertyString("sensoren");
 
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
// |  |  |  |   |   |   |------------------------------------------------ [6]
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
            /* @var $temperature float */
            $temperature = ($bytes[4] * 256 + $bytes[5] - 1000) / 10;
            /* @var $humidity int */
            $pressure    = ($bytes[16] * 256) + $bytes[17];
            // nur definierte Sensoren behandeln
            if ($bytes[2] == $this->ReadPropertyInteger("SensorID")) {
                $old_temp = GetValueFloat($this->GetIDForIdent("TEMPERATUR"));
                //$this->SendDebug ('OLD_TEMP', $old_temp, 0);
                //$this->SendDebug ('NEW_TEMP', $temperature, 0);
                if ($temperature != $old_temp) {
                    if (($temperature >= ($old_temp + 0.3)) || ($temperature <= ($old_temp - 0.3 ))) {
                        SetValueFloat($this->GetIDForIdent("TEMPERATUR"), $temperature);
                        SetValueInteger($this->GetIDForIdent("PRESSURE"), $pressure);
                    }
                }
            }
        } //if
    }
//function
}

//class
?>