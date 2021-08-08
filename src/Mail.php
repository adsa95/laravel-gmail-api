<?php

namespace MartijnWagena\Gmail;

use Carbon\Carbon;
use Google_Service_Gmail;
use Illuminate\Support\Collection;
use Base64Url\Base64Url;

class Mail extends Gmail
{
    protected $service;
    protected $start_date;

    /**
     * Mail constructor.
     */
    public function __construct()
    {
        parent::__construct();

        $this->service = new Google_Service_Gmail($this->client);
    }

    /**
     * @param $date
     * @return $this
     */
    public function setStartDate(Carbon $date) {
        $this->start_date = $date->getTimestamp();

        return $this;
    }

    /**
     * Get all email ids since the service is added to Watermelon
     *
     * @return Collection
     */
    public function fetch()
    {
        $mails = $this->service->users_messages->listUsersMessages('me', [
            'q' => 'after:' . $this->start_date . ' AND -label:chat AND -label:sent'
        ]);

        $collect = collect();
        foreach($mails as $m) {
            $obj = new \stdClass();
            $obj->id = $m->id;
            $obj->threadId = $m->threadId;

            $collect->push($obj);
        }

        return $collect->reverse();
    }

    /**
     * @param $messageId
     * @param $threadId
     * @return array|bool
     */
    public function getMessage($messageId, $threadId)
    {
        if(!$messageId) {
            return false;
        }
        $mail = $this->service->users_messages->get('me', $messageId, ['format' => 'full']);

        // Get actual mail payload
        $payload = $mail->getPayload();

        $message = [
            'id' => $messageId,
            'thread_id' => $threadId,
            'message_id' => null,
            'parent_id' => null,
            'date' => null,
            'to' => [],
            'cc' => [],
            'subject' => null,
            'from' => null,
            'reply-to' => null,
            'body' => [
                'html' => null,
                'text' => null,
            ],
            'attachments' => [],
        ];

        // Collect headers
        $headers = collect($payload->headers);

        // Set MessageId
        $message_id = $this->findProperty($headers, 'Message-Id');
        if(!$message_id) {
            $message_id = $this->findProperty($headers, 'Message-ID');
        }
        $message['message_id'] = $message_id;

        // Set ParentId
        $message['parent_id'] = $this->findProperty($headers, 'References');

        // Set Date
        $message['date'] = Carbon::createFromTimestamp(substr($mail->internalDate, 0, -3));

        // Set receiving user
        $message['to'] = $this->mapContacts($this->findProperty($headers, 'To'));

        // Set Cc when available
        if($cc = $this->findProperty($headers, 'Cc')) {
            $message['cc'] = $this->mapContacts($cc);
        }

        // Set Subject
        $message['subject'] = $this->findProperty($headers, 'Subject');

        // Set Sender address
        $message['from'] = $this->mapContact($this->findProperty($headers, 'From'));

        // Set Reply-to when available
        if($replyTo = $this->findProperty($headers, 'Reply-To')) {
            $message['reply-to'] = $this->mapContact($replyTo);
        }

        $message['body'] = $this->parsePart($messageId, $payload);

        return $message;
    }

    /**
     * Parses an array of message parts and fills the $body parameter with html, text and attachments
     *
     * @param $messageId
     * @param $parts
     * @param $body
     * @return array
     */
    private function parseParts($messageId, $parts, $body){
        foreach ($parts as $part){
            $body = $this->parsePart($messageId, $part, $body);
        }

        return $body;
    }

    /**
     * Parses a message part and fills the $body parameter with html, text and attachments
     *
     * @param $messageId
     * @param $part
     * @param $body
     * @return array
     */
    private function parsePart($messageId, $part, $body = array()){
        if($this->isMultipart($part)){
            $body = $this->parseParts($messageId, $part['parts'], $body);
        }else if($this->isAttachment($part)){
            $body = $this->parseAttachment($messageId, $part, $body);
        }else if($this->isText($part)){
            $body['text'] = $this->getDecodedBody($part);
        }else{
            $body['html'] = $this->getDecodedBody($part);
        }

        return $body;
    }

    /**
     * Parses a part into an attachment and adds it to the $body['attachments'] array
     *
     * @param $messageId
     * @param $part
     * @param $body
     * @return array
     */
    private function parseAttachment($messageId, $part, $body){
        $body['attachments'][] = [
            'mimeType' => $part['mimeType'],
            'contents' => $this->getAttachment($messageId, $part['body']['attachmentId']),
            'filename' => $part['filename'],
        ];

        return $body;
    }

    /**
     * @param $part
     * @return boolean
     */
    private function isAttachment($part){
        return (isset($part['filename']) && !empty($part['filename']) && isset($part['body']['attachmentId']));
    }

    /**
     * @param $part
     * @return boolean
     */
    private function isMultipart($part){
        return strpos($part['mimeType'], 'multipart') === 0;
    }

    /**
     * @param $part
     * @return boolean
     */
    private function isText($part){
        return $part['mimeType'] == 'text/plain';
    }

    /**
     * @param $part
     * @return string
     */
    private function getDecodedBody($part) {

        return trim(Base64Url::decode($part->getBody()->data));
    }

    /**
     * @param $messageId
     * @param $attachmentId
     * @return string
     */
    private function getAttachment($messageId, $attachmentId) {
        $attachment = $this->service->users_messages_attachments->get('me', $messageId, $attachmentId);

        return Base64Url::decode($attachment->getData());
    }

    /**
     * @param $contacts
     * @return Collection
     */
    private function mapContacts($contacts) {
        $to_explode = collect(explode(', ', $contacts));
        $t = $to_explode->map(function($to) {
            return $this->mapContact($to);
        });
        return $t;
    }

    /**
     * @param $contact
     * @return array
     */
    private function mapContact($contact) {
        $t = explode(' ', $contact);

        $i_address = $t[count($t) - 1];
        $address = str_replace(['<', '>'], '', $i_address);
        $address = trim($address);

        return [
            'name' => trim(str_replace($i_address, '', $contact)),
            'email' => $address,
        ];
    }
}