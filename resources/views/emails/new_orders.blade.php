<?php
/**
 * @Author: Bernard Hanna
 * @Date:   2023-05-15 12:53:27
 * @Last Modified by:   Bernard Hanna
 * @Last Modified time: 2023-05-24 11:31:35
 */
?>
<h1>New Orders Synced from SWSM</h1>
<table>
    <tr>
        <th>Created At</th>
        <th>Basket ID</th>
    </tr>
    @foreach ($orders as $order)
        <tr>
            <td>{{ $order->created_at }}</td>
            <td>{{ $order->basket_id }}</td>
        </tr>
    @endforeach
</table>
