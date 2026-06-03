package com.smspaybd.reader;

import org.json.JSONException;
import org.json.JSONObject;
import java.security.MessageDigest;

public class MessageItem {
    public final String sender;
    public final String body;
    public final long receivedAt;
    public final int subscriptionId;
    public final int simSlot;
    public final String methodOption;

    public MessageItem(String sender, String body, long receivedAt) {
        this(sender, body, receivedAt, -1, -1, "");
    }

    public MessageItem(String sender, String body, long receivedAt, int subscriptionId, int simSlot, String methodOption) {
        this.sender = sender;
        this.body = body;
        this.receivedAt = receivedAt;
        this.subscriptionId = subscriptionId;
        this.simSlot = simSlot;
        this.methodOption = methodOption == null ? "" : methodOption;
    }

    public JSONObject toJson() throws JSONException {
        JSONObject object = new JSONObject();
        object.put("sender", sender);
        object.put("body", body);
        object.put("received_at_ms", receivedAt);
        object.put("received_at", new java.text.SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ssXXX", java.util.Locale.US).format(new java.util.Date(receivedAt)));
        if (subscriptionId >= 0) object.put("android_subscription_id", subscriptionId);
        if (simSlot >= 0) object.put("android_sim_slot", simSlot);
        if (methodOption.length() > 0) object.put("method_option", methodOption);
        object.put("local_hash", hash());
        return object;
    }

    public String hash() {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] bytes = digest.digest(((sender == null ? "" : sender.trim().toLowerCase(java.util.Locale.US)) + "|" + (body == null ? "" : body.trim())).getBytes("UTF-8"));
            StringBuilder builder = new StringBuilder();
            for (byte b : bytes) builder.append(String.format("%02x", b));
            return builder.toString();
        } catch (Exception ignored) {
            return String.valueOf((sender + body).hashCode());
        }
    }
}
