package com.smspaybd.reader;

import android.content.Context;
import android.content.SharedPreferences;
import org.json.JSONArray;
import org.json.JSONObject;
import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Set;

public class QueueStore {
    private static final String PREF = "queue";
    private static final String KEY = "messages";
    private static final int MAX_ITEMS = 500;

    public static synchronized boolean add(Context context, MessageItem item) {
        try {
            JSONArray array = readArray(context);
            String hash = item.hash();
            for (int i = 0; i < array.length(); i++) {
                JSONObject queued = array.getJSONObject(i);
                if (hash.equals(queued.optString("local_hash"))) return false;
            }

            array.put(item.toJson());
            while (array.length() > MAX_ITEMS) {
                JSONArray trimmed = new JSONArray();
                for (int i = 1; i < array.length(); i++) trimmed.put(array.getJSONObject(i));
                array = trimmed;
            }
            prefs(context).edit().putString(KEY, array.toString()).apply();
            return true;
        } catch (Exception ignored) {
            return false;
        }
    }

    public static synchronized List<MessageItem> all(Context context) {
        List<MessageItem> list = new ArrayList<>();
        JSONArray array = readArray(context);
        for (int i = 0; i < array.length(); i++) {
            try {
                JSONObject o = array.getJSONObject(i);
                list.add(new MessageItem(
                    o.getString("sender"),
                    o.getString("body"),
                    o.optLong("received_at_ms", System.currentTimeMillis()),
                    o.optInt("android_subscription_id", -1),
                    o.optInt("android_sim_slot", -1),
                    o.optString("method_option", "")
                ));
            } catch (Exception ignored) {}
        }
        return list;
    }

    public static synchronized int count(Context context) {
        return readArray(context).length();
    }

    public static synchronized String preview(Context context, int limit) {
        JSONArray array = readArray(context);
        if (array.length() == 0) return "No queued SMS.";

        StringBuilder builder = new StringBuilder();
        int start = Math.max(0, array.length() - Math.max(1, limit));
        for (int i = start; i < array.length(); i++) {
            try {
                JSONObject o = array.getJSONObject(i);
                String sender = o.optString("sender", "-");
                String body = o.optString("body", "").replaceAll("\\s+", " ").trim();
                if (body.length() > 78) body = body.substring(0, 78) + "...";
                builder.append("• ").append(sender).append(": ").append(body);
                if (i < array.length() - 1) builder.append("\n");
            } catch (Exception ignored) {}
        }
        return builder.toString();
    }

    public static synchronized void clear(Context context) {
        prefs(context).edit().remove(KEY).apply();
    }

    public static synchronized void remove(Context context, List<MessageItem> syncedItems) {
        if (syncedItems == null || syncedItems.isEmpty()) return;

        Set<String> syncedHashes = new HashSet<>();
        for (MessageItem item : syncedItems) syncedHashes.add(item.hash());

        JSONArray current = readArray(context);
        JSONArray remaining = new JSONArray();
        try {
            for (int i = 0; i < current.length(); i++) {
                JSONObject queued = current.getJSONObject(i);
                if (!syncedHashes.contains(queued.optString("local_hash"))) {
                    remaining.put(queued);
                }
            }
            prefs(context).edit().putString(KEY, remaining.toString()).apply();
        } catch (Exception ignored) {}
    }

    private static JSONArray readArray(Context context) {
        try { return new JSONArray(prefs(context).getString(KEY, "[]")); } catch (Exception e) { return new JSONArray(); }
    }

    private static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences(PREF, Context.MODE_PRIVATE);
    }
}
