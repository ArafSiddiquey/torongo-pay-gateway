package com.smspaybd.reader;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;

public class KeepAliveService extends Service {
    private static final String CHANNEL_ID = "torongo_verify_keep_alive";
    private static final int NOTIFICATION_ID = 1206;
    private static final long LOOP_MS = 30_000L;

    private Handler handler;
    private Runnable loop;
    private String lastMessage = "Starting background sync.";

    public static void start(Context context) {
        try {
            Intent intent = new Intent(context.getApplicationContext(), KeepAliveService.class);
            ContextCompat.startForegroundService(context.getApplicationContext(), intent);
        } catch (Exception ignored) {}
    }

    public static void refresh(Context context) {
        start(context);
    }

    @Override public void onCreate() {
        super.onCreate();
        createChannel();
        startForeground(NOTIFICATION_ID, notification("Background sync active."));
        handler = new Handler(Looper.getMainLooper());
        loop = () -> {
            runSyncCycle();
            handler.postDelayed(loop, LOOP_MS);
        };
        handler.post(loop);
    }

    @Override public int onStartCommand(Intent intent, int flags, int startId) {
        updateNotification(lastMessage);
        return START_STICKY;
    }

    @Override public void onDestroy() {
        if (handler != null && loop != null) {
            handler.removeCallbacks(loop);
        }
        KeepAliveService.start(this);
        super.onDestroy();
    }

    @Override public void onTaskRemoved(Intent rootIntent) {
        KeepAliveService.start(this);
        super.onTaskRemoved(rootIntent);
    }

    @Override public IBinder onBind(Intent intent) {
        return null;
    }

    private void runSyncCycle() {
        if (!hasSetup()) {
            lastMessage = "Setup required. Open app and scan setup QR.";
            updateNotification(lastMessage);
            return;
        }

        InboxScanner.scanRecentOfficial(this);
        int queued = QueueStore.count(this);
        lastMessage = queued > 0 ? "Queued SMS: " + queued + ". Syncing now." : "No queued SMS. Sending heartbeat.";
        updateNotification(lastMessage);

        if (queued > 0) {
            SyncClient.sync(this, (ok, message) -> {
                lastMessage = message;
                updateNotification(message);
                SyncClient.sendOutgoingSms(this);
            });
        } else {
            SyncClient.heartbeat(this, (ok, message) -> {
                lastMessage = message;
                updateNotification(message);
                SyncClient.sendOutgoingSms(this);
            });
        }
    }

    private boolean hasSetup() {
        SharedPreferences p = getSharedPreferences("setup", MODE_PRIVATE);
        return p.getString("server", "").length() >= 8 && p.getString("api_key", "").length() >= 8;
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return;
        NotificationChannel channel = new NotificationChannel(
            CHANNEL_ID,
            "Torongo Verify background sync",
            NotificationManager.IMPORTANCE_LOW
        );
        channel.setDescription("Keeps SMS verification sync running in the background.");
        channel.setShowBadge(false);
        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) manager.createNotificationChannel(channel);
    }

    private void updateNotification(String message) {
        NotificationManager manager = (NotificationManager) getSystemService(Context.NOTIFICATION_SERVICE);
        if (manager != null) {
            manager.notify(NOTIFICATION_ID, notification(message));
        }
    }

    private Notification notification(String message) {
        int queued = QueueStore.count(this);
        Intent intent = new Intent(this, MainActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        int flags = PendingIntent.FLAG_UPDATE_CURRENT;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) flags |= PendingIntent.FLAG_IMMUTABLE;
        PendingIntent pendingIntent = PendingIntent.getActivity(this, 0, intent, flags);

        return new NotificationCompat.Builder(this, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_torongo_mark)
            .setContentTitle("Torongo Verify is running")
            .setContentText("Queued SMS: " + queued + " • " + message)
            .setStyle(new NotificationCompat.BigTextStyle().bigText("Queued SMS: " + queued + "\n" + message))
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .build();
    }
}
