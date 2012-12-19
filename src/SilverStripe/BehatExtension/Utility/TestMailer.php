<?php

namespace SilverStripe\BehatExtension\Utility;

/**
 * Same principle as core TestMailer class,
 * but saves emails in the database instead in order
 * to share the state between PHP calls (CLI vs. browser).
 */
class TestMailer extends \Mailer {

	protected $table = '_Behat_TestMailer';

	/**
	 * Send a plain-text email.
	 * TestMailer will merely record that the email was asked to be sent, without sending anything.
	 */
	public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customHeaders = false) {
		$this->initTable();

		$this->saveEmail(array(
			'Type' => 'plain',
			'To' => $to,
			'From' => $from,
			'Subject' => $subject,
			'Content' => $plainContent,
			'PlainContent' => $plainContent,
			'AttachedFiles' => implode(',', $attachedFiles),
			'CustomHeaders' => implode(',', $customHeaders),
		));

		return true;
	}
	
	/**
	 * Send a multi-part HTML email
	 * TestMailer will merely record that the email was asked to be sent, without sending anything.
	 */
	public function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customHeaders = false,
			$plainContent = false, $inlineImages = false) {

		$this->initTable();

		$this->saveEmail(array(
			'Type' => 'html',
			'To' => $to,
			'From' => $from,
			'Subject' => $subject,
			'Content' => $htmlContent,
			'PlainContent' => $plainContent,
			'AttachedFiles' => implode(',', $attachedFiles),
			'CustomHeaders' => implode(',', $customHeaders),
		));

		return true;
	}
	
	/**
	 * Clear the log of emails sent
	 */
	public function clearEmails() {
		$this->initTable();

		$db = $this->getDb();
		$db->query(sprintf('TRUNCATE TABLE "%s"', $this->table));
	}

	/**
	 * Search for an email that was sent.
	 * All of the parameters can either be a string, or, if they start with "/", a PREG-compatible regular expression.
	 * @param $to
	 * @param $from
	 * @param $subject
	 * @param $content
	 * @return array Contains the keys: 'type', 'to', 'from', 'subject', 'content', 'plainContent', 'attachedFiles',
	 *               'customHeaders', 'htmlContent', 'inlineImages'
	 */
	public function findEmail($to = null, $from = null, $subject = null, $content = null) {
		$this->initTable();

		$args = func_get_args();
		$db = $this->getDb();
		$emails = $db->query(sprintf('SELECT * FROM "%s"', $this->table));
		foreach($emails as $email) {
			$matched = true;

			foreach(array('To','From','Subject','Content') as $i => $field) {
				$value = (isset($args[$i])) ? $args[$i] : null;
				if($value) {
					if($value[0] == '/') $matched = preg_match($value, $email[$field]);
					else $matched = ($value == $email[$field]);
					if(!$matched) break;
				}
			}
			if($matched) return $email;
		}
	}

	protected function initTable() {
		$db = $this->getDb();
		if(!$db->hasTable($this->table)) {
			$db->beginSchemaUpdate();
			$db->requireTable($this->table, array(
				'Type' => 'Enum("plain,html")',
				'From' => 'Text',
				'To' => 'Text',
				'Subject' => 'Text',
				'Content' => 'Text',
				'PlainContent' => 'Text',
				'AttachedFiles' => 'Text',
				'CustomHeaders' => 'Text',
			));
			$db->endSchemaUpdate();
		}
	}

	protected function saveEmail($data) {
		$db = $this->getDb();
		$data = array_filter($data);
		$manipulation = array(
			$this->table => array(
				'command' => 'insert',
				'fields' => array()
			)
		);
		foreach($data as $k => $v) {
			$manipulation[$this->table]['fields'][$k] = $db->prepStringForDB($v);
		}
		$db->manipulate($manipulation);
	}

	protected function getDb() {
		return \DB::getConn();
	}

}
