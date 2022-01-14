<?php
/* Copyright (C) 2022 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    core/triggers/interface_99_modBillmysales_BillmysalesTriggers.class.php
 * \ingroup billmysales
 * \brief   Example trigger.
 *
 * Put detailed description here.
 *
 * \remarks You can create other triggers by copying this one.
 * - File name should be either:
 *      - interface_99_modBillmysales_MyTrigger.class.php
 *      - interface_99_all_MyTrigger.class.php
 * - The file must stay in core/triggers
 * - The class name must be InterfaceMytrigger
 * - The constructor method must be named InterfaceMytrigger
 * - The name property name must be MyTrigger
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';


/**
 *  Class of triggers for Billmysales module
 */
class InterfaceBillmysalesTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "demo";
		$this->description = "Billmysales triggers.";
		// 'development', 'experimental', 'dolibarr' or version
		$this->version = 'development';
		$this->picto = 'billmysales@billmysales';
	}

	/**
	 * Trigger name
	 *
	 * @return string Name of trigger file
	 */
	public function getName()
	{
		return $this->name;
	}

	/**
	 * Trigger description
	 *
	 * @return string Description of trigger file
	 */
	public function getDesc()
	{
		return $this->description;
	}


	/**
	 * Function called when a Dolibarrr business event is done.
	 * All functions "runTrigger" are triggered if file
	 * is inside directory core/triggers
	 *
	 * @param string 		$action 	Event action code
	 * @param CommonObject 	$object 	Object
	 * @param User 			$user 		Object user
	 * @param Translate 	$langs 		Object langs
	 * @param Conf 			$conf 		Object conf
	 * @return int              		<0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
	    $methodName = 'trigger'.ucfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($action)))));
		$callback = array($this, $methodName);
		// si el método existe se llama
		if (is_callable($callback)) {
			return call_user_func($callback, $object, $user, $langs, $conf);
		};
        // no se encontró método para procesar el webhook
		return 0;
	}

	private function triggerBillPayed($facture, $user, $langs, $conf)
	{
	    $societe = new Societe($this->db);
	    $societe->fetch($facture->socid);
	    $data = json_encode([
            'facture' => $facture,
	        'societe' => $societe,
	        //'billing_contact_id' => $facture->getIdBillingContact(),
	        //'shipping_contact_id' => $facture->getIdShippingContact(),
	    ]);
	    $signature = $this->sign_data($data, $conf->global->BILLMYSALES_WEBHOOK_TOKEN);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        // asignar cabecera
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json", "X-DolibarrBMS-Hmac-Sha256:".$signature));
        // realizar consulta a curl recuperando cabecera y cuerpo
        curl_setopt($curl, CURLOPT_URL, $conf->global->BILLMYSALES_WEBHOOK_URL);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        $resultado = curl_exec($curl);
        if (!$resultado) {
            $curl_error = curl_error($curl);
            return 1;
        }
        if ($conf->global->BILLMYSALES_WEBHOOK_LOG == "1") {
    	    dol_syslog(
    		    "Trigger '".$this->name."' for action '$action' launched by ".__FILE__.". facture_id: ".$facture->id." - error: ".$curl_error." - json_invoice: ".$data
    		);
	    }
	    curl_close($curl);
        return 0;
	}

	private function sign_data($data, $token)
	{
        return base64_encode(hash_hmac('sha256', trim($data), $token, true));
	}
}
