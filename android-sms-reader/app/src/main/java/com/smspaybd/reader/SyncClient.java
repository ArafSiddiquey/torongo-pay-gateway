package com.smspaybd.reader;

import android.content.Context;
import android.content.SharedPreferences;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.os.Handler;
import android.os.Looper;
import org.json.JSONArray;
import org.json.JSONObject;
import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URI;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class SyncClient {
    private static volatile boolean running = false;
    private static volatile boolean heartbeatRunning = false;
    private static volatile long lastHeartbeatAttempt = 0;
    private static final long HEARTBEAT_MIN_GAP_MS = 20_000L;

    public interface Callback {
        void done(boolean ok, String message);
    }

    public static void sync(Context context) {
        sync(context, null);
    }

    public static void sync(Context context, Callback callback) {
        Context appContext = context.getApplicationContext();
        new Thread(() -> {
            synchronized (SyncClient.class) {
                if (running) {
                    finish(callback, true, "Sync already running.");
                    return;
                }
                running = true;
            }
            HttpURLConnection conn = null;
            try {
                if (!isOnline(appContext)) {
                    finish(callback, false, "No internet connection.");
                    return;
                }
                SharedPreferences prefs = appContext.getSharedPreferences("setup", Context.MODE_PRIVATE);
                String server = prefs.getString("server", "");
                String key = prefs.getString("api_key", "");
                if (server.length() < 8 || key.length() < 8) {
                    finish(callback, false, "Server URL or API key missing.");
                    return;
                }

                List<MessageItem> items = QueueStore.all(appContext);
                if (items.isEmpty()) {
                    heartbeat(appContext, callback);
                    return;
                }

                JSONArray messages = new JSONArray();
                for (MessageItem item : items) messages.put(item.toJson());
                JSONObject payload = new JSONObject();
                payload.put("messages", messages);
                payload.put("device_name", prefs.getString("device_name", ""));

                String lastError = "";
                for (String endpoint : endpoints(server, "/api/v1/sms/sync")) {
                    try {
                        conn = (HttpURLConnection) new URL(endpoint).openConnection();
                        conn.setConnectTimeout(8000);
                        conn.setReadTimeout(12000);
                        conn.setRequestMethod("POST");
                        conn.setRequestProperty("Content-Type", "application/json");
                        conn.setRequestProperty("Accept", "application/json");
                        conn.setRequestProperty("X-Device-Key", key);
                        conn.setDoOutput(true);
                        OutputStream os = conn.getOutputStream();
                        os.write(payload.toString().getBytes(StandardCharsets.UTF_8));
                        os.close();
                        int code = conn.getResponseCode();
                        String response = read(conn, code);
                        if (code >= 200 && code < 300) {
                            saveCheckpoint(appContext, response);
                            QueueStore.remove(appContext, items);
                            finish(callback, true, "Synced " + items.size() + " SMS. " + response);
                            return;
                        }
                        lastError = "HTTP " + code;
                    } catch (Exception endpointException) {
                        lastError = endpointException.getMessage();
                    } finally {
                        if (conn != null) {
                            conn.disconnect();
                            conn = null;
                        }
                    }
                }
                finish(callback, false, "Sync failed: " + lastError);
            } catch (Exception exception) {
                finish(callback, false, "Sync failed: " + exception.getMessage());
            } finally {
                if (conn != null) conn.disconnect();
                synchronized (SyncClient.class) {
                    running = false;
                }
            }
        }).start();
    }

    public static void heartbeat(Context context) {
        heartbeat(context, null);
    }

    public static void sendOutgoingSms(Context context) {
        sendOutgoingSms(context, null);
    }

    public static void sendOutgoingSms(Context context, Callback callback) {
        Context appContext = context.getApplicationContext();
        new Thread(() -> {
            try {
                if (!OutgoingSmsSender.enabled(appContext)) {
                    finish(callback, true, "Outgoing SMS is off.");
                    return;
                }
                if (!OutgoingSmsSender.hasPermission(appContext)) {
                    finish(callback, false, "SMS send permission missing.");
                    return;
                }
                if (!isOnline(appContext)) {
                    finish(callback, false, "No internet connection.");
                    return;
                }

                SharedPreferences prefs = appContext.getSharedPreferences("setup", Context.MODE_PRIVATE);
                String server = prefs.getString("server", "");
                String key = prefs.getString("api_key", "");
                if (server.length() < 8 || key.length() < 8) {
                    finish(callback, false, "Server URL or API key missing.");
                    return;
                }

                JSONArray messages = fetchOutgoing(appContext, server, key);
                if (messages.length() == 0) {
                    finish(callback, true, "No outgoing SMS pending.");
                    return;
                }

                JSONArray results = OutgoingSmsSender.send(appContext, messages);
                if (results.length() > 0) {
                    reportOutgoing(appContext, server, key, results);
                }
                finish(callback, true, "Processed outgoing SMS: " + results.length());
            } catch (Exception exception) {
                finish(callback, false, "Outgoing SMS failed: " + exception.getMessage());
            }
        }).start();
    }

    public static void heartbeat(Context context, Callback callback) {
        Context appContext = context.getApplicationContext();
        synchronized (SyncClient.class) {
            long now = System.currentTimeMillis();
            if (heartbeatRunning || (callback == null && now - lastHeartbeatAttempt < HEARTBEAT_MIN_GAP_MS)) {
                finish(callback, true, "Heartbeat already running.");
                return;
            }
            heartbeatRunning = true;
            lastHeartbeatAttempt = now;
        }
        new Thread(() -> {
            HttpURLConnection conn = null;
            try {
                if (!isOnline(appContext)) {
                    finish(callback, false, "No internet connection.");
                    return;
                }
                SharedPreferences prefs = appContext.getSharedPreferences("setup", Context.MODE_PRIVATE);
                String server = prefs.getString("server", "");
                String key = prefs.getString("api_key", "");
                if (server.length() < 8 || key.length() < 8) {
                    finish(callback, false, "Server URL or API key missing.");
                    return;
                }

                JSONObject payload = new JSONObject();
                payload.put("device_name", prefs.getString("device_name", ""));

                String lastError = "";
                for (String endpoint : endpoints(server, "/api/v1/sms/heartbeat")) {
                    try {
                        conn = (HttpURLConnection) new URL(endpoint).openConnection();
                        conn.setConnectTimeout(6000);
                        conn.setReadTimeout(8000);
                        conn.setRequestMethod("POST");
                        conn.setRequestProperty("Content-Type", "application/json");
                        conn.setRequestProperty("Accept", "application/json");
                        conn.setRequestProperty("X-Device-Key", key);
                        conn.setDoOutput(true);
                        OutputStream os = conn.getOutputStream();
                        os.write(payload.toString().getBytes(StandardCharsets.UTF_8));
                        os.close();
                        int code = conn.getResponseCode();
                        String response = read(conn, code);
                        if (code >= 200 && code < 300) {
                            saveCheckpoint(appContext, response);
                            finish(callback, true, "Device online.");
                            return;
                        }
                        lastError = "HTTP " + code;
                    } catch (Exception endpointException) {
                        lastError = endpointException.getMessage();
                    } finally {
                        if (conn != null) {
                            conn.disconnect();
                            conn = null;
                        }
                    }
                }
                finish(callback, false, "Heartbeat failed: " + lastError);
            } catch (Exception exception) {
                finish(callback, false, "Heartbeat failed: " + exception.getMessage());
            } finally {
                if (conn != null) conn.disconnect();
                synchronized (SyncClient.class) {
                    heartbeatRunning = false;
                }
            }
        }).start();
    }

    private static String read(HttpURLConnection conn, int code) {
        try {
            BufferedReader br = new BufferedReader(new InputStreamReader(
                code >= 200 && code < 300 ? conn.getInputStream() : conn.getErrorStream(),
                StandardCharsets.UTF_8
            ));
            StringBuilder sb = new StringBuilder();
            String line;
            while ((line = br.readLine()) != null) sb.append(line);
            return sb.toString();
        } catch (Exception ignored) {
            return "";
        }
    }

    private static JSONArray fetchOutgoing(Context context, String server, String key) throws Exception {
        JSONObject payload = new JSONObject();
        payload.put("device_name", context.getSharedPreferences("setup", Context.MODE_PRIVATE).getString("device_name", ""));
        String response = post(server, "/api/v1/sms/outgoing/fetch", key, payload, 8000, 10000);
        return new JSONObject(response == null ? "{}" : response).optJSONArray("messages") == null
            ? new JSONArray()
            : new JSONObject(response).optJSONArray("messages");
    }

    private static void reportOutgoing(Context context, String server, String key, JSONArray results) throws Exception {
        JSONObject payload = new JSONObject();
        payload.put("device_name", context.getSharedPreferences("setup", Context.MODE_PRIVATE).getString("device_name", ""));
        payload.put("messages", results);
        post(server, "/api/v1/sms/outgoing/report", key, payload, 8000, 10000);
    }

    private static String post(String server, String path, String key, JSONObject payload, int connectTimeout, int readTimeout) throws Exception {
        HttpURLConnection conn = null;
        String lastError = "";
        for (String endpoint : endpoints(server, path)) {
            try {
                conn = (HttpURLConnection) new URL(endpoint).openConnection();
                conn.setConnectTimeout(connectTimeout);
                conn.setReadTimeout(readTimeout);
                conn.setRequestMethod("POST");
                conn.setRequestProperty("Content-Type", "application/json");
                conn.setRequestProperty("Accept", "application/json");
                conn.setRequestProperty("X-Device-Key", key);
                conn.setDoOutput(true);
                OutputStream os = conn.getOutputStream();
                os.write(payload.toString().getBytes(StandardCharsets.UTF_8));
                os.close();
                int code = conn.getResponseCode();
                String response = read(conn, code);
                if (code >= 200 && code < 300) return response;
                lastError = "HTTP " + code;
            } catch (Exception endpointException) {
                lastError = endpointException.getMessage();
            } finally {
                if (conn != null) {
                    conn.disconnect();
                    conn = null;
                }
            }
        }
        throw new Exception(lastError);
    }

    private static void finish(Callback callback, boolean ok, String message) {
        if (callback == null) return;
        new Handler(Looper.getMainLooper()).post(() -> callback.done(ok, message));
    }

    private static void saveCheckpoint(Context context, String response) {
        try {
            JSONObject data = new JSONObject(response == null ? "{}" : response);
            String value = data.optString("last_sms_received_at", "");
            long millis = parseIsoMillis(value);
            if (millis > 0) {
                context.getSharedPreferences("setup", Context.MODE_PRIVATE)
                    .edit()
                    .putString("last_sms_received_at", value)
                    .putLong("last_sms_received_at_ms", millis)
                    .apply();
            }
        } catch (Exception ignored) {}
    }

    private static long parseIsoMillis(String value) {
        if (value == null || value.length() == 0) return 0L;
        String normalized = value.replaceAll("\\.\\d+", "");
        String[] patterns = {
            "yyyy-MM-dd'T'HH:mm:ssXXX",
            "yyyy-MM-dd'T'HH:mm:ssZ",
            "yyyy-MM-dd HH:mm:ss"
        };
        for (String pattern : patterns) {
            try {
                Date date = new SimpleDateFormat(pattern, Locale.US).parse(normalized);
                if (date != null) return date.getTime();
            } catch (Exception ignored) {}
        }
        return 0L;
    }

    private static List<String> endpoints(String server, String path) {
        List<String> urls = new ArrayList<>();
        String primary = server.replaceAll("/$", "");
        urls.add(primary + path);

        try {
            URI uri = new URI(primary);
            String host = uri.getHost();
            int port = uri.getPort() > 0 ? uri.getPort() : 80;
            if (port == 8000 && host != null && (host.startsWith("192.168.") || host.startsWith("10.") || host.equals("localhost") || host.equals("127.0.0.1"))) {
                String fallback = "http://127.0.0.1:" + port;
                if (!fallback.equals(primary)) {
                    urls.add(fallback + path);
                }
            }
        } catch (Exception ignored) {}

        return urls;
    }

    private static boolean isOnline(Context context) {
        try {
            ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
            NetworkInfo info = manager == null ? null : manager.getActiveNetworkInfo();
            return info != null && info.isConnected();
        } catch (Exception ignored) {
            return false;
        }
    }
}
