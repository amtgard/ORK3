<?php
final class Mail {
	protected $to;
	protected $from;
	protected $sender;
	protected $subject;
	protected $text;
	protected $html;
	protected $attachments = array();
	protected $protocol = 'mail';
	protected $hostname;
	protected $username;
	protected $password;
	protected $port = 25;
	protected $timeout = 5;

	public function __construct($protocol = 'mail', $hostname = '', $username = '', $password = '', $port = '25', $timeout = '5') {
		$this->protocol = $protocol;
		$this->hostname = $hostname;
		$this->username = $username;
		$this->password = $password;
		$this->port = $port;
		$this->timeout = $timeout;   
	}
   
	public function setTo($to) {
		$this->to = $to;
	}
   
	public function setFrom($from) {
		$this->from = $from;
	}
	
	public function addheader($header, $value) {
		$this->headers[$header] = $value;
	}
	
	public function setSender($sender) {
		$this->sender = $sender;
	}
	
	public function setSubject($subject) {
		$this->subject = $subject;
	}
	
	public function setText($text) {
		$this->text = $text;
	}
	
	public function setHtml($html) {
		$this->html = $html;
	}
	
	public function addAttachment($attachment) {
		if (!is_array($attachment)) {
			$this->attachments[] = $attachment;
		} else{
			$this->attachments = array_merge($this->attachments, $attachment);
		}
	}
	
	public function send() {   
		if (!$this->to) {
			exit('Error: E-Mail to required!');
		}
	
		if (!$this->from) {
			exit('Error: E-Mail from required!');
		}
	
		if (!$this->sender) {
			exit('Error: E-Mail sender required!');
		}
	
		if (!$this->subject) {
			exit('Error: E-Mail subject required!');
		}
	
		if ((!$this->text) && (!$this->html)) {
			exit('Error: E-Mail message required!');
		}
	
		if (is_array($this->to)) {
			$to = implode(',', $this->to);
		} else {
			$to = $this->to;
		}
	
		$boundary = 'Boundary' . md5(time()); 
		
		if (strtoupper(substr(PHP_OS, 0, 3) == 'WIN')) {
			$eol = "\r\n";
		} elseif (strtoupper(substr(PHP_OS, 0, 3) == 'MAC')) { 
			$eol = "\r";
		} else {
			$eol = "\n";
		}    
		// Seems required
		$eol = "\r\n";
	
		$header = '';
	
		if ($this->protocol != 'mail') {
			$header .= 'To: ' . $to . $eol;
			$header .= 'Subject: ' . $this->subject . $eol;
		}
	
		$header .= 'From: ' . $this->sender . '<' . $this->from . '>' . $eol;
		$header .= 'Reply-To: ' . $this->sender . '<' . $this->from . '>' . $eol;   
		$header .= 'Return-Path: ' . $this->from . $eol;
		//$header .= 'X-Mailer: PHP/' . phpversion() . $eol; 
		//$header .= 'MIME-Version: 1.0' . $eol;
//		$header .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol; 
	
		if (!$this->html) {
			$message  = '--' . $boundary . $eol; 
			$message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
			$message .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
			$message .= $this->text . $eol;
		} else {
			//$message  = '--' . $boundary . $eol;
			//$message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '_alt"' . $eol . $eol;
			//$message .= '--' . $boundary . '_alt' . $eol;
			//$message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
			//$message .= 'Content-Transfer-Encoding: 8bit' . $eol;
		
			if ($this->text) {
				$message .= $this->text . $eol;
			} else {
				//$message .= 'This is a HTML email and your email client software does not support HTML email!' . $eol;
			}   
		
			//$message .= '--' . $boundary . '_alt' . $eol;
			//$message .= '--' . $boundary . $eol;
			$message .= 'Content-Type: text/html; charset="utf-8"' . $eol . $eol;
			//$message .= 'Content-Transfer-Encoding: 8bit' . $eol . $eol;
			$message .= "<html><body>" . $this->html . "</body></html>" . $eol . $eol . '--' . $eol . $eol;
			//$message .= '--' . $boundary . '--' . $eol;
			//$message .= '--' . $boundary . '_alt--' . $eol;      
		}
	
		foreach ($this->attachments as $attachment) { 
			$filename = basename($attachment); 
			$handle = fopen($attachment, 'r');
			$content = fread($handle, filesize($attachment));
	
			fclose($handle); 
	
			$message .= '--' . $boundary . $eol;
			$message .= 'Content-Type: application/octetstream' . $eol;   
			$message .= 'Content-Transfer-Encoding: base64' . $eol;
			$message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . $eol;
			$message .= 'Content-ID: <' . $filename . '>' . $eol . $eol;
			$message .= chunk_split(base64_encode($content));
		} 
	
		if ($this->protocol == 'mail') {
			ini_set('sendmail_from', $this->from);
	
			mail($to, $this->subject, $message, $header); 
		} elseif ($this->protocol == 'smtp') {
			$handle = fsockopen($this->hostname, $this->port, $errno, $errstr, $this->timeout);   
	
			if (!$handle) {
				echo_error('Error: ' . $errstr . ' (' . $errno . ')');
			} else {
				if (substr(PHP_OS, 0, 3) != 'WIN') {
					socket_set_timeout($handle, $this->timeout, 0);
				}
		
				while ($line = fgets($handle, 515)) {
					if (substr($line, 3, 1) == ' ') {
						break;
					}
				}
		
				if (substr($this->hostname, 0, 3) == 'tls') {
					fputs($handle, 'STARTTLS' . $eol);
				
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
					
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 220) {
						echo_error('Error: STARTTLS not accepted from server! ' . $reply . ' ' . __LINE__);
					}               
				}
		
				if (!empty($this->username)  && !empty($this->password)) {
					fputs($handle, 'EHLO ' . getenv('SERVER_NAME') . $eol);
				
					$reply = '';
				
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
				
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 250) {
						echo_error('Error: EHLO not accepted from server! ' . $reply . ' ' . __LINE__);
					}
					$reply = '';
					fputs($handle, 'STARTTLS' . $eol);
				
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
					
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 220) {
						echo_error('Error: STARTTLS not accepted from server! ' . $reply . ' ' . __LINE__);
					}

					$crypto_ok = stream_socket_enable_crypto($handle, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
					
					fputs($handle, 'AUTH LOGIN' . $eol);
		
					$reply = '';
		
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
					
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 334) {
						echo_error('Error: AUTH LOGIN not accepted from server! ' . $reply . ' ' . __LINE__);
					}
		
					fputs($handle, base64_encode($this->username) . $eol);
		
					$reply = '';
		
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
						
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 334) {
						echo_error('Error: Username not accepted from server! ' . $reply . ' ' . __LINE__);
					}            
		
					fputs($handle, base64_encode($this->password) . $eol);
		
					$reply = '';
		
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
					
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 235) {
						echo_error('Error: Password not accepted from server! ' . $reply . ' ' . __LINE__);               
					}   
				} else {
					fputs($handle, 'HELO ' . getenv('SERVER_NAME') . $eol);
		
					$reply = '';
		
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
					
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if (substr($reply, 0, 3) != 250) {
						echo_error('Error: HELO not accepted from server! ' . $reply . ' ' . __LINE__);
					}            
				}
		
				fputs($handle, 'MAIL FROM: <' . $this->from . '>' . $eol);
		
				$reply = '';
				
				while ($line = fgets($handle, 515)) {
					$reply .= $line;
				
					if (substr($line, 3, 1) == ' ') {
						break;
					}
				}
		
				if (substr($reply, 0, 3) != 250) {
					echo_error('Error: MAIL FROM not accepted from server! ' . $reply . ' ' . __LINE__);
				}
		
				if (!is_array($this->to)) {
					fputs($handle, 'RCPT TO: <' . $this->to . '>' . $eol);
				
					$reply = '';
				
					while ($line = fgets($handle, 515)) {
						$reply .= $line;
				
						if (substr($line, 3, 1) == ' ') {
							break;
						}
					}
		
					if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
						echo_error('Error: RCPT TO not accepted from server! ' . $reply . ' ' . __LINE__);
					}         
				} else {
					foreach ($this->to as $recipient) {
						fputs($handle, 'RCPT TO: <' . $recipient . '>' . $eol);
		
						$reply = '';
		
						while ($line = fgets($handle, 515)) {
							$reply .= $line;
							
							if (substr($line, 3, 1) == ' ') {
								break;
							}
						}
		
						if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
							echo_error('Error: RCPT TO not accepted from server! ' . $reply . ' ' . __LINE__);
						}                  
					}
				}
				
				fputs($handle, 'DATA' . $eol);
				
				$reply = '';
					
				while ($line = fgets($handle, 515)) {
					$reply .= $line;
					
					if (substr($line, 3, 1) == ' ') {
						break;
					}
				}
			
				if (substr($reply, 0, 3) != 354) {
					echo_error('Error: DATA not accepted from server! ' . $reply . ' ' . __LINE__);
				}
			
				fputs($handle, $header . $message . $eol);
				fputs($handle, '.' . $eol);
			
				$reply = '';
			
				while ($line = fgets($handle, 515)) {
					$reply .= $line;
				
					if (substr($line, 3, 1) == ' ') { 
						break;
					}
				}
			
				if (substr($reply, 0, 3) != 250) {
					echo_error('Error: DATA not accepted from server! ' . $reply . ' ' . __LINE__);
				}
			
				fputs($handle, 'QUIT' . $eol);
				
				$reply = '';
				
				while ($line = fgets($handle, 515)) {
					$reply .= $line;
				
					if (substr($line, 3, 1) == ' ') {
						break;
					}
				}
			
				if (substr($reply, 0, 3) != 221) {
					echo_error('Error: QUIT not accepted from server! ' . $reply . ' ' . __LINE__);
				}         
			
				fclose($handle);
			}
		}
	}
}

function echo_error($str) {
  echo $str . "<br>\n\n";
}

?>