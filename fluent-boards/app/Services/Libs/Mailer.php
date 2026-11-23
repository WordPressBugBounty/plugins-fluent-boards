<?php

namespace FluentBoards\App\Services\Libs;

use Throwable;
use WP_Error;

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

    /** Send a notification with result reporting, retaining the generic send() contract. */
    public function sendNotification(array $context)
    {
        $context['recipient'] = $this->to;
        self::notifyReady($context);
        return $this->sendGroupedNotifications([$context]);
    }

    /** Other channels receive the selected event independently of email batching and retries. */
    public static function notifyReady(array $context)
    {
        try {
            do_action('fluent_boards/notification_ready', $context);
        } catch (Throwable $e) {
            error_log('FluentBoards notification observer failed: fluent_boards/notification_ready');
        }
    }

    /** Send one email and report its transport result for each included event. */
    public function sendGroupedNotifications(array $contexts)
    {
        $error = null;
        try {
            $sent = $this->send();
        } catch (Throwable $e) {
            $error = $e;
            $sent = false;
        }
        foreach ($contexts as $context) {
            $context['recipient'] = $this->to;
            if ($sent) {
                self::dispatchResult(true, $context);
            } else {
                self::reportFailure($context, $error);
            }
        }
        return (bool) $sent;
    }

    /** Log only type/ID; email_failed receives context and a WP_Error for diagnostics. */
    public static function reportFailure(array $context, ?Throwable $exception = null)
    {
        error_log(sprintf(
            'FluentBoards email failed: %s #%d.',
            sanitize_key($context['type'] ?? 'unknown'),
            absint($context['task_id'] ?? $context['comment_id'] ?? 0)
        ));
        $error = new WP_Error('fluent_boards_email_failed', $exception ? $exception->getMessage() : 'wp_mail returned false.');
        self::dispatchResult(false, $context, $error);
    }

    /** Reporting observers must not interrupt delivery or turn accepted mail into failure. */
    private static function dispatchResult(bool $sent, array $context, $error = null)
    {
        try {
            if ($sent) {
                do_action('fluent_boards/email_sent', $context, $error);
            } else {
                do_action('fluent_boards/email_failed', $context, $error);
            }
        } catch (Throwable $e) {
            error_log('FluentBoards email reporting observer failed: ' . ($sent ? 'fluent_boards/email_sent' : 'fluent_boards/email_failed'));
        }
    }

}
