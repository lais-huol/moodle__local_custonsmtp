<?php

defined('MOODLE_INTERNAL') || die();

function CriaAccount($obAccount){
    global $DB;
    $obAccount->data_inclusao = time();
    
    if($obAccount->id){
        $DB->update_record("custonsmtp_accounts", $obAccount);
    }
    else{
        $DB->insert_record("custonsmtp_accounts", $obAccount);
    }
}

function DeletaAccount($id){
    global $DB;
    $DB->delete_records("custonsmtp_accounts", array('id'=>$id));
}


function SendMailToQueue($emailOb){
    global $DB;
    $emailOb->timecreated = time();
    return $DB->insert_record("custonsmtp_email", $emailOb);
}

//TODO
function email_to_user_override($user, $from, $subject, $messagetext, $messagehtml = '', $attachment = '', $attachname = '',
        $usetrueaddress = true, $replyto = '', $replytoname = '', $wordwrapwidth = 79){
        email_to_user($user, $from, $subject, $messagetext, $messagehtml, $attachment, $attachname,
        $usetrueaddress, $replyto, $replytoname, $wordwrapwidth);
        global $CFG, $PAGE, $SITE;

        if (empty($user) or empty($user->id)) {
            debugging('Can not send email to null user', DEBUG_DEVELOPER);
            return false;
        }
    
        if (empty($user->email)) {
            debugging('Can not send email to user without email: '.$user->id, DEBUG_DEVELOPER);
            return false;
        }
    
        if (!empty($user->deleted)) {
            debugging('Can not send email to deleted user: '.$user->id, DEBUG_DEVELOPER);
            return false;
        }
    
        if (defined('BEHAT_SITE_RUNNING')) {
            // Fake email sending in behat.
            return true;
        }
    
        if (!empty($CFG->noemailever)) {
            // Hidden setting for development sites, set in config.php if needed.
            debugging('Not sending email due to $CFG->noemailever config setting', DEBUG_NORMAL);
            return true;
        }
    
        if (email_should_be_diverted($user->email)) {
            $subject = "[DIVERTED {$user->email}] $subject";
            $user = clone($user);
            $user->email = $CFG->divertallemailsto;
        }
    
        // Skip mail to suspended users.
        if ((isset($user->auth) && $user->auth=='nologin') or (isset($user->suspended) && $user->suspended)) {
            return true;
        }
    
        if (!validate_email($user->email)) {
            // We can not send emails to invalid addresses - it might create security issue or confuse the mailer.
            debugging("email_to_user: User $user->id (".fullname($user).") email ($user->email) is invalid! Not sending.");
            return false;
        }
    
        if (over_bounce_threshold($user)) {
            debugging("email_to_user: User $user->id (".fullname($user).") is over bounce threshold! Not sending.");
            return false;
        }
    
        // TLD .invalid  is specifically reserved for invalid domain names.
        // For More information, see {@link http://tools.ietf.org/html/rfc2606#section-2}.
        if (substr($user->email, -8) == '.invalid') {
            debugging("email_to_user: User $user->id (".fullname($user).") email domain ($user->email) is invalid! Not sending.");
            return true; // This is not an error.
        }
    
        // If the user is a remote mnet user, parse the email text for URL to the
        // wwwroot and modify the url to direct the user's browser to login at their
        // home site (identity provider - idp) before hitting the link itself.
        if (is_mnet_remote_user($user)) {
            require_once($CFG->dirroot.'/mnet/lib.php');
    
            $jumpurl = mnet_get_idp_jump_url($user);
            $callback = partial('mnet_sso_apply_indirection', $jumpurl);
    
            $messagetext = preg_replace_callback("%($CFG->wwwroot[^[:space:]]*)%",
                    $callback,
                    $messagetext);
            $messagehtml = preg_replace_callback("%href=[\"'`]($CFG->wwwroot[\w_:\?=#&@/;.~-]*)[\"'`]%",
                    $callback,
                    $messagehtml);
        }

        /*$mail = get_mailer();
        
        if (!empty($mail->SMTPDebug)) {
            echo '<pre>' . "\n";
        }
        */
        $temprecipients = array();
        $tempreplyto = array();
    
        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;
    
        if (!validate_email($noreplyaddress)) {
            debugging('email_to_user: Invalid noreply-email '.s($noreplyaddress));
            $noreplyaddress = $noreplyaddressdefault;
        }
    
        // Make up an email address for handling bounces.
        if (!empty($CFG->handlebounces)) {
            $modargs = 'B'.base64_encode(pack('V', $user->id)).substr(md5($user->email), 0, 16);
            $mail->Sender = generate_email_processing_address(0, $modargs);
        } else {
            $mail->Sender = $noreplyaddress;
        }
    
        // Make sure that the explicit replyto is valid, fall back to the implicit one.
        if (!empty($replyto) && !validate_email($replyto)) {
            debugging('email_to_user: Invalid replyto-email '.s($replyto));
            $replyto = $noreplyaddress;
        }
    
        if (is_string($from)) { // So we can pass whatever we want if there is need.
            $mail->From     = $noreplyaddress;
            $mail->FromName = $from;
        // Check if using the true address is true, and the email is in the list of allowed domains for sending email,
        // and that the senders email setting is either displayed to everyone, or display to only other users that are enrolled
        // in a course with the sender.
        } else if ($usetrueaddress && can_send_from_real_email_address($from, $user)) {
            if (!validate_email($from->email)) {
                debugging('email_to_user: Invalid from-email '.s($from->email).' - not sending');
                // Better not to use $noreplyaddress in this case.
                return false;
            }
            $mail->From = $from->email;
            $fromdetails = new stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia == EMAIL_VIA_ALWAYS) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($from->email, fullname($from));
            }
        } else {
            $mail->From = $noreplyaddress;
            $fromdetails = new stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia != EMAIL_VIA_NEVER) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($noreplyaddress, get_string('noreplyname'));
            }
        }
    
        if (!empty($replyto)) {
            $tempreplyto[] = array($replyto, $replytoname);
        }
    
        $temprecipients[] = array($user->email, fullname($user));
    
        // Set word wrap.
        $mail->WordWrap = $wordwrapwidth;
    
        if (!empty($from->customheaders)) {
            // Add custom headers.
            if (is_array($from->customheaders)) {
                foreach ($from->customheaders as $customheader) {
                    $mail->addCustomHeader($customheader);
                }
            } else {
                $mail->addCustomHeader($from->customheaders);
            }
        }
    
        // If the X-PHP-Originating-Script email header is on then also add an additional
        // header with details of where exactly in moodle the email was triggered from,
        // either a call to message_send() or to email_to_user().
        if (ini_get('mail.add_x_header')) {
    
            $stack = debug_backtrace(false);
            $origin = $stack[0];
    
            foreach ($stack as $depth => $call) {
                if ($call['function'] == 'message_send') {
                    $origin = $call;
                }
            }
    
            $originheader = $CFG->wwwroot . ' => ' . gethostname() . ':'
                 . str_replace($CFG->dirroot . '/', '', $origin['file']) . ':' . $origin['line'];
            $mail->addCustomHeader('X-Moodle-Originating-Script: ' . $originheader);
        }
    
        if (!empty($from->priority)) {
            $mail->Priority = $from->priority;
        }
    
        $renderer = $PAGE->get_renderer('core');
        $context = array(
            'sitefullname' => $SITE->fullname,
            'siteshortname' => $SITE->shortname,
            'sitewwwroot' => $CFG->wwwroot,
            'subject' => $subject,
            'prefix' => $CFG->emailsubjectprefix,
            'to' => $user->email,
            'toname' => fullname($user),
            'from' => $mail->From,
            'fromname' => $mail->FromName,
        );
        if (!empty($tempreplyto[0])) {
            $context['replyto'] = $tempreplyto[0][0];
            $context['replytoname'] = $tempreplyto[0][1];
        }
        if ($user->id > 0) {
            $context['touserid'] = $user->id;
            $context['tousername'] = $user->username;
        }
    
        if (!empty($user->mailformat) && $user->mailformat == 1) {
            // Only process html templates if the user preferences allow html email.
    
            if (!$messagehtml) {
                // If no html has been given, BUT there is an html wrapping template then
                // auto convert the text to html and then wrap it.
                $messagehtml = trim(text_to_html($messagetext));
            }
            $context['body'] = $messagehtml;
            $messagehtml = $renderer->render_from_template('core/email_html', $context);
        }
    
        $context['body'] = html_to_text(nl2br($messagetext));
        $mail->Subject = $renderer->render_from_template('core/email_subject', $context);
        $mail->FromName = $renderer->render_from_template('core/email_fromname', $context);
        $messagetext = $renderer->render_from_template('core/email_text', $context);
    
        // Autogenerate a MessageID if it's missing.
        if (empty($mail->MessageID)) {
            $mail->MessageID = generate_email_messageid();
        }
    
        if ($messagehtml && !empty($user->mailformat) && $user->mailformat == 1) {
            // Don't ever send HTML to users who don't want it.
            $mail->isHTML(true);
            $mail->Encoding = 'quoted-printable';
            $mail->Body    =  $messagehtml;
            $mail->AltBody =  "\n$messagetext\n";
        } else {
            $mail->IsHTML(false);
            $mail->Body =  "\n$messagetext\n";
        }
    
        if ($attachment && $attachname) {
            if (preg_match( "~\\.\\.~" , $attachment )) {
                // Security check for ".." in dir path.
                $supportuser = core_user::get_support_user();
                $temprecipients[] = array($supportuser->email, fullname($supportuser, true));
                $mail->addStringAttachment('Error in attachment.  User attempted to attach a filename with a unsafe name.', 'error.txt', '8bit', 'text/plain');
            } else {
                require_once($CFG->libdir.'/filelib.php');
                $mimetype = mimeinfo('type', $attachname);
    
                $attachmentpath = $attachment;
    
                // Before doing the comparison, make sure that the paths are correct (Windows uses slashes in the other direction).
                $attachpath = str_replace('\\', '/', $attachmentpath);
    
                // Add allowed paths to an array (also check if it's not empty).
                $allowedpaths = array_filter([
                    $CFG->cachedir,
                    $CFG->dataroot,
                    $CFG->dirroot,
                    $CFG->localcachedir,
                    $CFG->tempdir
                ]);
                // Set addpath to true.
                $addpath = true;
                // Check if attachment includes one of the allowed paths.
                foreach ($allowedpaths as $tmpvar) {
                    // Make sure both variables are normalised before comparing.
                    $temppath = str_replace('\\', '/', realpath($tmpvar));
                    // Set addpath to false if the attachment includes one of the allowed paths.
                    if (strpos($attachpath, $temppath) === 0) {
                        $addpath = false;
                        break;
                    }
                }
    
                // If the attachment is a full path to a file in the multiple allowed paths, use it as is,
                // otherwise assume it is a relative path from the dataroot (for backwards compatibility reasons).
                if ($addpath == true) {
                    $attachmentpath = $CFG->dataroot . '/' . $attachmentpath;
                }
    
                $mail->addAttachment($attachmentpath, $attachname, 'base64', $mimetype);
            }
        }
    
        // Check if the email should be sent in an other charset then the default UTF-8.
        if ((!empty($CFG->sitemailcharset) || !empty($CFG->allowusermailcharset))) {
    
            // Use the defined site mail charset or eventually the one preferred by the recipient.
            $charset = $CFG->sitemailcharset;
            if (!empty($CFG->allowusermailcharset)) {
                if ($useremailcharset = get_user_preferences('mailcharset', '0', $user->id)) {
                    $charset = $useremailcharset;
                }
            }
    
            // Convert all the necessary strings if the charset is supported.
            $charsets = get_list_of_charsets();
            unset($charsets['UTF-8']);
            if (in_array($charset, $charsets)) {
                $mail->CharSet  = $charset;
                $mail->FromName = core_text::convert($mail->FromName, 'utf-8', strtolower($charset));
                $mail->Subject  = core_text::convert($mail->Subject, 'utf-8', strtolower($charset));
                $mail->Body     = core_text::convert($mail->Body, 'utf-8', strtolower($charset));
                $mail->AltBody  = core_text::convert($mail->AltBody, 'utf-8', strtolower($charset));
    
                foreach ($temprecipients as $key => $values) {
                    $temprecipients[$key][1] = core_text::convert($values[1], 'utf-8', strtolower($charset));
                }
                foreach ($tempreplyto as $key => $values) {
                    $tempreplyto[$key][1] = core_text::convert($values[1], 'utf-8', strtolower($charset));
                }
            }
        }
    
        foreach ($temprecipients as $values) {
            $mail->addAddress($values[0], $values[1]);
        }
        foreach ($tempreplyto as $values) {
            $mail->addReplyTo($values[0], $values[1]);
        }
    
        if ($mail->send()) {
            set_send_count($user);
            if (!empty($mail->SMTPDebug)) {
                echo '</pre>';
            }
            return true;
        } else {
            // Trigger event for failing to send email.
            $event = \core\event\email_failed::create(array(
                'context' => context_system::instance(),
                'userid' => $from->id,
                'relateduserid' => $user->id,
                'other' => array(
                    'subject' => $subject,
                    'message' => $messagetext,
                    'errorinfo' => $mail->ErrorInfo
                )
            ));
            $event->trigger();
            if (CLI_SCRIPT) {
                mtrace('Error: lib/moodlelib.php email_to_user(): '.$mail->ErrorInfo);
            }
            if (!empty($mail->SMTPDebug)) {
                echo '</pre>';
            }
            return false;
        }

}

function EnviaEmails($dadosform){
    $mailOb = new stdClass();
    var_dump($dadosform);
    var_dump(format_text($dadosform->body['text'], $dadosform->body['format']));
    $mailOb->to_adress = $user->email;
    $mailOb->from_mail = $account->username;
    $mailOb->from_name = 'AVASUS';
    $mailOb->title = $titulo;
    $mailOb->body = format_text($dadosform->body['text'], $dadosform->body['format']);
    $mailOb->bodyHTML = $texto;
    $mailOb->account = $account->id;
    die("a");
    SendMailToQueue($mailOb);
}
