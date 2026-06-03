package com.smspaybd.reader;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;

public class NetworkChangeReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (intent == null || intent.getAction() == null) return;
        KeepAliveService.start(context);
        if (!isOnline(context)) return;
        InboxScanner.scanRecentOfficial(context);
        if (QueueStore.all(context).isEmpty()) {
            SyncClient.heartbeat(context);
        } else {
            SyncClient.sync(context);
        }
    }

    private boolean isOnline(Context context) {
        try {
            ConnectivityManager manager = (ConnectivityManager) context.getSystemService(Context.CONNECTIVITY_SERVICE);
            NetworkInfo info = manager == null ? null : manager.getActiveNetworkInfo();
            return info != null && info.isConnected();
        } catch (Exception ignored) {
            return false;
        }
    }
}
