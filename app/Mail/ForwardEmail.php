<?php

namespace App\Mail;

use App\Alias;
use App\EmailData;
use App\Helpers\AlreadyEncryptedSigner;
use App\Helpers\OpenPGPSigner;
use App\Notifications\GpgKeyExpired;
use App\Recipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Swift_Signers_DKIMSigner;
use Swift_SwiftException;

class ForwardEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    protected $user;
    protected $alias;
    protected $sender;
    protected $displayFrom;
    protected $replyToAddress;
    protected $emailSubject;
    protected $emailText;
    protected $emailHtml;
    protected $emailAttachments;
    protected $deactivateUrl;
    protected $bannerLocation;
    protected $fingerprint;
    protected $openpgpsigner;
    protected $dkimSigner;
    protected $encryptedParts;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(Alias $alias, EmailData $emailData, Recipient $recipient)
    {
        $this->encryptedParts = $emailData->encryptedParts ?? null;
        $fingerprint = $recipient->should_encrypt && !$this->encryptedParts ? $recipient->fingerprint : null;

        $this->user = $alias->user;
        $this->alias = $alias;
        $this->sender = $emailData->sender;
        $this->displayFrom = $emailData->display_from;
        $this->replyToAddress = $emailData->reply_to_address ?? null;
        $this->emailSubject = $emailData->subject;
        $this->emailText = $emailData->text;
        $this->emailHtml = $emailData->html;
        $this->emailAttachments = $emailData->attachments;
        $this->deactivateUrl = URL::signedRoute('deactivate', ['alias' => $alias->id]);
        $this->bannerLocation = $this->alias->user->banner_location;

        if ($this->fingerprint = $fingerprint) {
            try {
                $this->openpgpsigner = OpenPGPSigner::newInstance(config('anonaddy.signing_key_fingerprint'), [], "~/.gnupg");
                $this->openpgpsigner->addRecipient($fingerprint);
            } catch (Swift_SwiftException $e) {
                info($e->getMessage());
                $this->openpgpsigner = null;

                $recipient->update(['should_encrypt' => false]);

                $recipient->notify(new GpgKeyExpired);
            }
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $replyToEmail = $this->alias->local_part . '+' . Str::replaceLast('@', '=', $this->sender) . '@' . $this->alias->domain;

        if ($this->alias->isCustomDomain()) {
            if ($this->alias->aliasable->isVerifiedForSending()) {
                $fromEmail = $this->alias->email;
                $returnPath = $this->alias->email;

                $this->dkimSigner = new Swift_Signers_DKIMSigner(config('anonaddy.dkim_signing_key'), $this->alias->domain, config('anonaddy.dkim_selector'));
                $this->dkimSigner->ignoreHeader('List-Unsubscribe');
                $this->dkimSigner->ignoreHeader('Return-Path');
            } else {
                $fromEmail = config('mail.from.address');
                $returnPath = config('anonaddy.return_path');
            }
        } else {
            $fromEmail = $this->alias->email;
            $returnPath = 'mailer@'.$this->alias->parentDomain();
        }

        $email =  $this
            ->from($fromEmail, base64_decode($this->displayFrom)." '".$this->sender."'")
            ->replyTo($replyToEmail)
            ->subject($this->user->email_subject ?? base64_decode($this->emailSubject))
            ->text('emails.forward.text')->with([
                'text' => base64_decode($this->emailText)
            ])
            ->with([
                'location' => $this->bannerLocation,
                'deactivateUrl' => $this->deactivateUrl,
                'aliasEmail' => $this->alias->email,
                'fromEmail' => $this->sender,
                'replacedSubject' => $this->user->email_subject ? ' with subject "' . base64_decode($this->emailSubject) . '"' : null
            ])
            ->withSwiftMessage(function ($message) use ($returnPath) {
                $message->getHeaders()
                        ->addTextHeader('List-Unsubscribe', '<mailto:' . $this->alias->id . '@unsubscribe.' . config('anonaddy.domain') . '?subject=unsubscribe>, <' . $this->deactivateUrl . '>');

                $message->getHeaders()
                        ->addTextHeader('Return-Path', $returnPath);

                $message->setId(bin2hex(random_bytes(16)).'@'.$this->alias->domain);

                if ($this->encryptedParts) {
                    $alreadyEncryptedSigner = new AlreadyEncryptedSigner($this->encryptedParts);

                    $message->attachSigner($alreadyEncryptedSigner);
                }

                if ($this->openpgpsigner) {
                    $message->attachSigner($this->openpgpsigner);
                } elseif ($this->dkimSigner) { // TODO fix issue with failing DKIM signature if message is encrypted
                    $message->attachSigner($this->dkimSigner);
                }
            });

        if ($this->emailHtml) {
            $email->view('emails.forward.html')->with([
                'html' => base64_decode($this->emailHtml)
            ]);
        }

        foreach ($this->emailAttachments as $attachment) {
            $email->attachData(
                base64_decode($attachment['stream']),
                base64_decode($attachment['file_name']),
                ['mime' => base64_decode($attachment['mime'])]
            );
        }

        return $email;
    }
}
