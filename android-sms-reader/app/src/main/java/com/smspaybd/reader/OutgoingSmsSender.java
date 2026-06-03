package com.smspaybd.reader;

import android.Manifest;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.telephony.SmsManager;
import org.json.JSONArray;
import org.json.JSONObject;
import java.util.ArrayList;

public class OutgoingSmsSender {
    public static final String PREF_SUBSCRIPTION_ID = "outgoing_sms_subscription_id";

    public static boolean enabled(Context context) {
        return context.getSharedPreferences("setup", Context.MODE_PRIVATE).getBoolean("outgoing_sms_enabled", false);
    }

    public static void setEnabled(Context context, boolean enabled) {
        context.getSharedPreferences("setup", Context.MODE_PRIVATE)
            .edit()
            .putBoolean("outgoing_sms_enabled", enabled)
            .apply();
    }

    public static boolean hasPermission(Context context) {
        return context.checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED;
    }

    public static int selectedSubscriptionId(Context context) {
        return context.getSharedPreferences("setup", Context.MODE_PRIVATE).getInt(PREF_SUBSCRIPTION_ID, -1);
    }

    public static void setSelectedSubscriptionId(Context context, int subscriptionId) {
        context.getSharedPreferences("setup", Context.MODE_PRIVATE)
            .edit()
            .putInt(PREF_SUBSCRIPTION_ID, subscriptionId)
            .apply();
    }

    public static JSONArray send(Context context, JSONArray messages) {
        JSONArray results = new JSONArray();
        if (!enabled(context) || !hasPermission(context)) {
            return results;
        }

        SmsManager manager = manager(context);
        for (int i = 0; i < messages.length(); i++) {
            try {
                JSONObject item = messages.getJSONObject(i);
                String recipient = item.optString("recipient", "");
                String message = item.optString("message", "");
                if (recipient.length() < 8 || message.length() == 0) {
                    results.put(result(item.optLong("id"), "failed", "Missing recipient or message."));
                    continue;
                }

                ArrayList<String> parts = manager.divideMessage(message);
                if (parts.size() > 1) {
                    manager.sendMultipartTextMessage(recipient, null, parts, null, null);
                } else {
                    manager.sendTextMessage(recipient, null, message, null, null);
                }

                results.put(result(item.optLong("id"), "sent", ""));
            } catch (Exception exception) {
                try {
                    JSONObject item = messages.getJSONObject(i);
                    results.put(result(item.optLong("id"), "failed", exception.getMessage()));
                } catch (Exception ignored) {}
            }
        }

        return results;
    }

    private static SmsManager manager(Context context) {
        int subscriptionId = selectedSubscriptionId(context);
        if (subscriptionId >= 0) {
            return SmsManager.getSmsManagerForSubscriptionId(subscriptionId);
        }

        return SmsManager.getDefault();
    }

    private static JSONObject result(long id, String status, String error) throws Exception {
        JSONObject result = new JSONObject();
        result.put("id", id);
        result.put("status", status);
        if (error != null && error.length() > 0) result.put("error", error);
        return result;
    }
}
