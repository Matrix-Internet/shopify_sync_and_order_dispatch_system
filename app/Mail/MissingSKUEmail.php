<?php
/**
 * @Author: Bernard Hanna
 * @Date:   2023-09-12 11:43:00
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-09-12 11:43:03
 */
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class MissingSkuEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $emailData;

    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    public function build()
    {
        return $this->subject('Order Error: Missing SKU')
            ->view('emails.missing-sku-error')
            ->with($this->emailData);
    }
}