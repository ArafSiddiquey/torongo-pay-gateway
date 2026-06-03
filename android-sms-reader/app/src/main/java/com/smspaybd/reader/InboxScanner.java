package com.smspaybd.reader;

import android.content.Context;
import android.content.SharedPreferences;
import android.database.Cursor;
import android.net.Uri;

public class InboxScanner {
    public static int scanRecentOfficial(Context context) {
        SharedPreferences prefs = context.getSharedPreferences("setup", Context.MODE_PRIVATE);
        String allowed = prefs.getString("methods", "bkash,nagad,rocket");
        long checkpoint = prefs.getLong("last_sms_received_at_ms", 0L);
        long fallback = System.currentTimeMillis() - (48L * 60L * 60L * 1000L);
        long since = checkpoint > 0 ? checkpoint : fallback;
        int queued = 0;

        Cursor cursor = null;
        try {
            cursor = context.getContentResolver().query(
                Uri.parse("content://sms/inbox"),
                null,
                "date >= ?",
                new String[]{String.valueOf(since)},
                "date ASC"
            );

            if (cursor == null) return 0;
            int addressIndex = cursor.getColumnIndex("address");
            int bodyIndex = cursor.getColumnIndex("body");
            int dateIndex = cursor.getColumnIndex("date");
            int subIdIndex = cursor.getColumnIndex("sub_id");
            if (subIdIndex < 0) subIdIndex = cursor.getColumnIndex("subscription_id");

            while (cursor.moveToNext()) {
                String sender = cursor.getString(addressIndex);
                String body = cursor.getString(bodyIndex);
                long receivedAt = cursor.getLong(dateIndex);
                int subscriptionId = subIdIndex >= 0 ? cursor.getInt(subIdIndex) : -1;
                if (OfficialSmsParser.isOfficial(sender, body, allowed)) {
                    SimRouting.Route route = SimRouting.routeFor(context, subscriptionId, sender, body);
                    if (QueueStore.add(context, new MessageItem(sender, body, receivedAt, subscriptionId, -1, route.option))) {
                        queued++;
                    }
                }
            }
        } catch (SecurityException ignored) {
            return 0;
        } catch (Exception ignored) {
            return queued;
        } finally {
            if (cursor != null) cursor.close();
        }

        return queued;
    }
}
