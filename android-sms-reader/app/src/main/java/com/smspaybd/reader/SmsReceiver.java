package com.smspaybd.reader;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.provider.Telephony;
import android.telephony.SmsMessage;

public class SmsReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction())) return;
        SharedPreferences prefs = context.getSharedPreferences("setup", Context.MODE_PRIVATE);
        String allowed = prefs.getString("methods", "bkash,nagad,rocket");

        SmsMessage[] parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        if (parts == null || parts.length == 0) return;

        String sender = parts[0].getDisplayOriginatingAddress();
        long receivedAt = parts[0].getTimestampMillis();
        int subscriptionId = intent.getIntExtra("subscription", intent.getIntExtra("android.telephony.extra.SUBSCRIPTION_INDEX", -1));
        int simSlot = intent.getIntExtra("slot", intent.getIntExtra("simSlot", -1));
        StringBuilder body = new StringBuilder();
        for (SmsMessage sms : parts) {
            if (sms.getTimestampMillis() < receivedAt) receivedAt = sms.getTimestampMillis();
            body.append(sms.getDisplayMessageBody());
        }

        String bodyText = body.toString();
        if (OfficialSmsParser.isOfficial(sender, bodyText, allowed)) {
            SimRouting.Route route = SimRouting.routeFor(context, subscriptionId, sender, bodyText);
            QueueStore.add(context, new MessageItem(sender, bodyText, receivedAt, subscriptionId, simSlot, route.option));
        }
        KeepAliveService.refresh(context);
        SyncClient.sync(context);
    }
}
