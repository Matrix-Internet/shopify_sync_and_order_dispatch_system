<?php
/**
 * @Author: Bernard Hanna
 * @Date:   2023-06-06 12:03:26
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-09-12 11:45:35
 */


namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderErrorMail extends Mailable
{
    use Queueable, SerializesModels;


    /**
     * Create a new message instance.
     *
     * @param array $emailData
     */
    public $emailData;
    public $errorType;
    
    public function __construct(array $emailData, $errorType)
    {
        $this->emailData = $emailData;
        $this->errorType = $errorType;
    }
    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $adminEmails = config('app.admin_emails');
        $ccAddress = config('app.mail_cc_address');
        $bccAddress = config('app.mail_bcc_address');
    
        if ($this->errorType === 'missing_sku') {
            return $this->subject('Order Error: Missing SKU')
                ->cc($ccAddress)
                ->bcc($bccAddress)
                ->to($adminEmails)
                ->view('emails.missing-sku-error')
                ->with($this->emailData);
        } elseif ($this->errorType === 'generic') {
            return $this->subject('Order Error: Generic Error')
                ->cc($ccAddress)
                ->bcc($bccAddress)
                ->to($adminEmails)
                ->view('emails.generic-error')
                ->with($this->emailData);
        }
    
        // Handle other cases or fallback to a default email
    }
}
