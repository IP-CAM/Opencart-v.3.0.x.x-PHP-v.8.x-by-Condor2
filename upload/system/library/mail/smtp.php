<?php
namespace Mail;
class Smtp extends \stdClass {
	protected $to = '';
	protected $from = '';
	protected $sender = '';
	protected $reply_to = '';
	protected $subject = '';
	protected $text = '';
	protected $html = '';
	protected $attachments = array();
	protected $smtp_hostname = '';
	protected $smtp_username = '';
	protected $smtp_password = '';
	protected $smtp_port = 25;
	protected $smtp_timeout = 5;
	protected $max_attempts = 3;
	protected $verp = false;

	public function __construct(array $args) {
		foreach ($args as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}
	}

	public function send() {
		if (is_array($this->to)) {
			$to = implode(',', $this->to);
		} else {
			$to = $this->to;
		}

		$boundary = '----=_NextPart_' . md5(time());

		$header = 'MIME-Version: 1.0' . PHP_EOL;
		$header .= 'To: <' . $to . '>' . PHP_EOL;
		$header .= 'Subject: =?UTF-8?B?' . base64_encode($this->subject) . '?=' . PHP_EOL;
		$header .= 'Date: ' . date('D, d M Y H:i:s O') . PHP_EOL;
		$header .= 'From: =?UTF-8?B?' . base64_encode($this->sender) . '?= <' . $this->from . '>' . PHP_EOL;

		if (!$this->reply_to) {
			$header .= 'Reply-To: =?UTF-8?B?' . base64_encode($this->sender) . '?= <' . $this->from . '>' . PHP_EOL;
		} else {
			$header .= 'Reply-To: =?UTF-8?B?' . base64_encode($this->reply_to) . '?= <' . $this->reply_to . '>' . PHP_EOL;
		}

		$header .= 'Return-Path: ' . $this->from . PHP_EOL;
		$header .= 'X-Mailer: PHP/' . PHP_VERSION . PHP_EOL;
		$header .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . PHP_EOL . PHP_EOL;

		if (!$this->html) {
			$message = '--' . $boundary . PHP_EOL;
			$message .= 'Content-Type: text/plain; charset="utf-8"' . PHP_EOL;
			$message .= 'Content-Transfer-Encoding: base64' . PHP_EOL . PHP_EOL;
			$message .= chunk_split(base64_encode($this->text)) . PHP_EOL;
		} else {
			$message = '--' . $boundary . PHP_EOL;
			$message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '_alt"' . PHP_EOL . PHP_EOL;
			$message .= '--' . $boundary . '_alt' . PHP_EOL;
			$message .= 'Content-Type: text/plain; charset="utf-8"' . PHP_EOL;
			$message .= 'Content-Transfer-Encoding: base64' . PHP_EOL . PHP_EOL;

			if ($this->text) {
				$message .= chunk_split(base64_encode($this->text)) . PHP_EOL;
			} else {
				$message .= chunk_split(base64_encode($this->html)) . PHP_EOL;
			}

			$message .= '--' . $boundary . '_alt' . PHP_EOL;
			$message .= 'Content-Type: text/html; charset="utf-8"' . PHP_EOL;
			$message .= 'Content-Transfer-Encoding: base64' . PHP_EOL . PHP_EOL;
			$message .= chunk_split(base64_encode($this->html)) . PHP_EOL;
			$message .= '--' . $boundary . '_alt--' . PHP_EOL;
		}

		foreach ($this->attachments as $attachment) {
			if (file_exists($attachment)) {
				$handle = fopen($attachment, 'r');

				$content = fread($handle, filesize($attachment));

				fclose($handle);

				$message .= '--' . $boundary . PHP_EOL;
				$message .= 'Content-Type: application/octet-stream; name="' . basename($attachment) . '"' . PHP_EOL;
				$message .= 'Content-Transfer-Encoding: base64' . PHP_EOL;
				$message .= 'Content-Disposition: attachment; filename="' . basename($attachment) . '"' . PHP_EOL;
				$message .= 'Content-ID: <' . urlencode(basename($attachment)) . '>' . PHP_EOL;
				$message .= 'X-Attachment-Id: ' . urlencode(basename($attachment)) . PHP_EOL . PHP_EOL;
				$message .= chunk_split(base64_encode($content));
			}
		}

		$message .= '--' . $boundary . '--' . PHP_EOL;

		if (substr($this->smtp_hostname, 0, 3) == 'tls') {
			$hostname = substr($this->smtp_hostname, 6);
		} else {
			$hostname = $this->smtp_hostname;
		}

		$handle = fsockopen($hostname, $this->smtp_port, $errno, $errstr, $this->smtp_timeout);

		if (!$handle) {
			throw new \Exception('Error: ' . $errstr . ' (' . $errno . ')');
		} else {
			if (substr(PHP_OS, 0, 3) != 'WIN') {
				stream_set_timeout($handle, $this->smtp_timeout, 0);
			}

			while ($line = fgets($handle, 515)) {
				if (substr($line, 3, 1) == ' ') {
					break;
				}
			}

			fwrite($handle, 'EHLO ' . getenv('SERVER_NAME') . "\r\n");

			$reply = '';

			while ($line = fgets($handle, 515)) {
				$reply .= $line;

				//some SMTP servers respond with 220 code before responding with 250. hence, we need to ignore 220 response string
				if (substr($reply, 0, 3) == 220 && substr($line, 3, 1) == ' ') {
					$reply = '';

					continue;
				} elseif (substr($line, 3, 1) == ' ') {
					break;
				}
			}

			if (substr($reply, 0, 3) != 250) {
				throw new \Exception('Error: EHLO not accepted from server!');
			}

			if (substr($this->smtp_hostname, 0, 3) == 'tls') {
				fwrite($handle, 'STARTTLS' . "\r\n");

				$this->handleReply($handle, 220, 'Error: STARTTLS not accepted from server!');

				stream_socket_enable_crypto($handle, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			}

			if (!empty($this->smtp_username) && !empty($this->smtp_password)) {
				fwrite($handle, 'EHLO ' . getenv('SERVER_NAME') . "\r\n");

				$this->handleReply($handle, 250, 'Error: EHLO not accepted from server!');

				fwrite($handle, 'AUTH LOGIN' . "\r\n");

				$this->handleReply($handle, 334, 'Error: AUTH LOGIN not accepted from server!');

				fwrite($handle, base64_encode($this->smtp_username) . "\r\n");

				$this->handleReply($handle, 334, 'Error: Username not accepted from server!');

				fwrite($handle, base64_encode($this->smtp_password) . "\r\n");

				$this->handleReply($handle, 235, 'Error: Password not accepted from server!');

			} else {
				fwrite($handle, 'HELO ' . getenv('SERVER_NAME') . "\r\n");

				$this->handleReply($handle, 250, 'Error: HELO not accepted from server!');
			}

			if ($this->verp) {
				fwrite($handle, 'MAIL FROM: <' . $this->from . '>XVERP' . "\r\n");
			} else {
				fwrite($handle, 'MAIL FROM: <' . $this->from . '>' . "\r\n");
			}

			$this->handleReply($handle, 250, 'Error: MAIL FROM not accepted from server!');

			if (!is_array($this->to)) {
				fwrite($handle, 'RCPT TO: <' . $this->to . '>' . "\r\n");

				$reply = $this->handleReply($handle, false, 'RCPT TO [!array]');

				if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
					throw new \Exception('Error: RCPT TO not accepted from server!');
				}
			} else {
				foreach ($this->to as $recipient) {
					fwrite($handle, 'RCPT TO: <' . $recipient . '>' . "\r\n");

					$reply = $this->handleReply($handle, false, 'RCPT TO [array]');

					if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
						throw new \Exception('Error: RCPT TO not accepted from server!');
					}
				}
			}

			fwrite($handle, 'DATA' . "\r\n");

			$this->handleReply($handle, 354, 'Error: DATA not accepted from server!');

			// According to rfc 821 we should not send more than 1000 including the CRLF
			$message = str_replace("\r\n", "\n", $header . $message);
			$message = str_replace("\r", "\n", $message);

			$length = (mb_detect_encoding($message, mb_detect_order(), true) == 'ASCII') ? 998 : 249;

			$lines = explode("\n", $message);

			foreach ($lines as $line) {
				$results = str_split($line, $length);

				foreach ($results as $result) {
					if (substr(PHP_OS, 0, 3) != 'WIN') {
						fwrite($handle, $result . "\r\n");
					} else {
						fwrite($handle, str_replace("\n", "\r\n", $result) . "\r\n");
					}
				}
			}

			fwrite($handle, '.' . "\r\n");

			$this->handleReply($handle, 250, 'Error: DATA not accepted from server!');

			fwrite($handle, 'QUIT' . "\r\n");

			$this->handleReply($handle, 221, 'Error: QUIT not accepted from server!');

			fclose($handle);
		}

		return true;
	}

	private function handleReply($handle, $status_code = false, $error_text = false, $counter = 0) {
		$reply = '';

		while (($line = fgets($handle, 515)) !== false) {
			$reply .= $line;

			if (substr($line, 3, 1) == ' ') {
				break;
			}
		}

		// Handle slowish server responses (generally due to policy servers)
		if (!$line && empty($reply) && $counter < $this->max_attempts) {
			sleep(1);

			$counter++;

			return $this->handleReply($handle, $status_code, $error_text, $counter);
		}

		if ($status_code) {
			if (substr($reply, 0, 3) != $status_code) {
				throw new \Exception($error_text);
			}
		}

		return $reply;
	}
}
