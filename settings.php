<?php
defined('MOODLE_INTERNAL') || die();


global $CFG, $PAGE,$DB;

if ($hassiteconfig) {
    $page = new admin_settingpage('custonsmtp', "Configurações SMTP");
    $page->add(new admin_setting_configtext('local_custonsmtp/timetosend', 'Hora do Envio','Hora do que serão enviados os emails',"0"));
    $page->add(new admin_setting_configtext('local_custonsmtp/maxemailsend', 'Maximo de envios diarios','Numero de envios diarios de emails (se usado ira sobescrever o limite nas contas quando o mesmo for maior e peremitirá limitar todas as contas)','2000'));
    $page->add(new admin_setting_configcheckbox('local_custonsmtp/limityall', 'Limite se aplica a todos','Se marcado o limite será aplicado a todas as contas combinadas',0));
    
    $accounts = $DB->get_records('custonsmtp_accounts');
    $accountsArray = array(0=>'Envia um por conta');
    foreach($accounts as $account){
        $accountsArray[$account->id] = 'Enviar primeiro a fila '.$account->name. ' e depois um por fila';
    }

    $page->add(new admin_setting_configselect('local_custonsmtp/priority', 'Prioridade','Como enviar',$idativotemp,$semestreArray));
    

    $ADMIN->add('localplugins', $page);
	$ADMIN->add('localplugins', new admin_externalpage('accountspage', 'Cadastro de Contas', "{$CFG->wwwroot}/local/custonsmtp/accounts.php"));
}