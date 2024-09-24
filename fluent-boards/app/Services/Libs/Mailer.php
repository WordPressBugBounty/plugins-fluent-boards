<?php

namespace FluentBoards\App\Services\Libs;

class Mailer
{
    private $subject = '';

    private $body = '';

    private $to = '';

    private $from = '';

    private $cc = [];

    private $bcc = [];

    private $replyTo = '';

    private $isHtml = true;

    public function __construct($to = '', $subject = '', $body = '')
    {
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;
    }

    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    public function to($to)
    {
        $this->to = $to;
        return $this;
    }

    public function setIsHtml($isHtml)
    {
        $this->isHtml = $isHtml;
        return $this;
    }

    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function addCC($cc)
    {
        $this->cc[] = $cc;
        return $this;
    }

    public function addBCC($bcc)
    {
        $this->bcc[] = $bcc;
        return $this;
    }

    public function setReplyTo($replyTo)
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    public function send()
    {
        if (!$this->to && !$this->cc && !$this->bcc) {
            return false;
        }

        $headers = [];

        if ($this->isHtml) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }

        if ($this->from) {
            $headers[] = 'From: ' . $this->from;
        }

        if ($this->cc) {
            $headers[] = 'Cc: ' . implode(',', $this->cc);
        }

        if ($this->bcc) {
            $headers[] = 'Bcc: ' . implode(',', $this->bcc);
        }

        if ($this->replyTo) {
            $headers[] = 'Reply-To: ' . $this->replyTo;
        }

        return wp_mail($this->to, $this->subject, $this->body, $headers);
    }
}