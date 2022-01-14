<?php
/**
 * BillMySales: Pasarela de Facturación
 * Copyright (C) SASCO SpA (https://sasco.cl)
 *
 * Este programa es software libre: usted puede redistribuirlo y/o
 * modificarlo bajo los términos de la Licencia Pública General Affero de GNU
 * publicada por la Fundación para el Software Libre, ya sea la versión
 * 3 de la Licencia, o (a su elección) cualquier versión posterior de la
 * misma.
 *
 * Este programa se distribuye con la esperanza de que sea útil, pero
 * SIN GARANTÍA ALGUNA; ni siquiera la garantía implícita
 * MERCANTIL o de APTITUD PARA UN PROPÓSITO DETERMINADO.
 * Consulte los detalles de la Licencia Pública General Affero de GNU para
 * obtener una información más detallada.
 *
 * Debería haber recibido una copia de la Licencia Pública General Affero de GNU
 * junto a este programa.
 * En caso contrario, consulte <http://www.gnu.org/licenses/agpl.html>.
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
		if ($conf->global->BILLMYSALES_WEBHOOK_LOG == '1') {
    	    dol_syslog('BillMySales Data BillPayed #'.$facture->id.': '.$data);
	    }
        $response = $this->api_post($conf->global->BILLMYSALES_WEBHOOK_URL, $data, $conf->global->BILLMYSALES_WEBHOOK_TOKEN);
		if ($conf->global->BILLMYSALES_WEBHOOK_LOG == '1') {
    	    dol_syslog('BillMySales Response BillPayed #'.$facture->id.': '.json_encode($response));
	    }
        return $response;
	}

	private function api_post($url, $data, $token)
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
				'Content-type: application/json',
                'X-DolibarrBMS-Hmac-Sha256: ' . $this->sign_data($data, $token)
            ],
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $response = curl_exec($curl);
        curl_close($curl);
        return json_decode($response, true);
    }

	private function sign_data($data, $token)
	{
        return base64_encode(hash_hmac('sha256', trim($data), $token, true));
	}
}
