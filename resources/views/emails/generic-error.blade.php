<?php
/**
 * @Author: Bernard Hanna
 * @Date:   2023-09-12 11:51:08
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-09-12 12:25:38
 */
?>
<!DOCTYPE html>
<html>
<head>
    <title>ERROR: There was a Problem sending <strong>{{ $emailData['order_number'] }}</strong> </title>
</head>
<body>
    <h1>Order Error</h1>
    <p>
        Order number <strong>{{ $emailData['order_number'] }}</strong> was not sent due to the following error(s):
    </p>
    
    <ul>
        @if (isset($emailData['error_messages']))
            @foreach ($emailData['error_messages'] as $error)
               Error: <li>{{ $error }}</li>
            @endforeach
        @else
            <li>Unknown error occurred. Please check the logs for more details.</li>
        @endif
    </ul>
    
    <p>
        This Order will be held on the system until the issue is corrected on your Shopify store. When this issue is resolved, we will attempt to send the order again. If the issue is not resolved within 90 days, the order will be canceled.
    </p>
</body>
</html>