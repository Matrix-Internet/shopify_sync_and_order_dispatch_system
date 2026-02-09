<?php
/**
 * @Author: Bernard Hanna
 * @Date:   2023-09-12 11:43:59
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-09-12 11:44:02
 */
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GenericErrorEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $emailData;

    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    public function build()
    {
        return $this->subject('Order Error: Generic Error')
            ->view('emails.generic-error')
            ->with($this->emailData);
    }
}