package com.smspaybd.reader;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.graphics.Typeface;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.os.PowerManager;
import android.provider.Settings;
import android.telephony.SubscriptionInfo;
import android.telephony.SubscriptionManager;
import android.text.InputType;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.CheckBox;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ScrollView;
import android.widget.TextView;
import android.widget.Toast;
import com.google.zxing.integration.android.IntentIntegrator;
import com.google.zxing.integration.android.IntentResult;
import org.json.JSONObject;
import java.util.List;

public class MainActivity extends Activity {
    EditText server, apiKey, deviceName;
    CheckBox bkash, nagad, rocket, outgoingSms;
    TextView status, queueList;
    LinearLayout root, setupArea, actionArea, bottomNav;
    boolean editingSetup = false;
    String activeTab = "sync";
    Handler heartbeatHandler;
    Runnable heartbeatTask;

    final int bg = Color.rgb(5, 14, 26);
    final int panel = Color.rgb(10, 25, 41);
    final int field = Color.rgb(14, 28, 46);
    final int text = Color.rgb(245, 248, 255);
    final int muted = Color.rgb(154, 177, 204);
    final int accent = Color.rgb(23, 217, 164);

    @Override public void onCreate(Bundle b) {
        super.onCreate(b);
        requestCorePermissions();

        LinearLayout screen = new LinearLayout(this);
        screen.setOrientation(LinearLayout.VERTICAL);
        screen.setBackgroundColor(bg);

        ScrollView scroll = new ScrollView(this);
        root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(dp(20), dp(22), dp(20), dp(18));
        root.setBackgroundColor(bg);
        scroll.addView(root);
        screen.addView(scroll, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, 0, 1));

        bottomNav = new LinearLayout(this);
        bottomNav.setOrientation(LinearLayout.HORIZONTAL);
        bottomNav.setPadding(dp(10), dp(5), dp(10), dp(3));
        bottomNav.setBackgroundColor(Color.rgb(8, 20, 34));
        screen.addView(bottomNav, new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(62)));
        setContentView(screen);

        renderHeader();

        setupArea = new LinearLayout(this);
        setupArea.setOrientation(LinearLayout.VERTICAL);
        root.addView(setupArea, matchWrap());

        actionArea = new LinearLayout(this);
        actionArea.setOrientation(LinearLayout.VERTICAL);
        root.addView(actionArea, matchWrap());

        LinearLayout statusCard = card();
        status = new TextView(this);
        status.setTextSize(12);
        status.setTextColor(muted);
        status.setLineSpacing(2, 1f);
        statusCard.addView(status);
        queueList = new TextView(this);
        queueList.setTextSize(11);
        queueList.setTextColor(Color.rgb(188, 211, 238));
        queueList.setLineSpacing(2, 1f);
        queueList.setPadding(0, dp(8), 0, 0);
        statusCard.addView(queueList);
        add(root, statusCard, 0, 4, 0, 0);

        editingSetup = !hasSetup();
        renderBottomNav();
        renderSetup();
        renderActions();
        updateStatus();
        KeepAliveService.start(this);
        ensureBackgroundAccess();
        startHeartbeat();
    }

    @Override protected void onResume() {
        super.onResume();
        updateStatus();
        KeepAliveService.start(this);
        ensureBackgroundAccess();
    }

    @Override protected void onDestroy() {
        super.onDestroy();
        if (heartbeatHandler != null && heartbeatTask != null) {
            heartbeatHandler.removeCallbacks(heartbeatTask);
        }
    }

    private void renderHeader() {
        LinearLayout header = new LinearLayout(this);
        header.setOrientation(LinearLayout.HORIZONTAL);
        header.setGravity(Gravity.TOP);
        add(root, header, 0, 0, 0, 12);

        ImageView logo = new ImageView(this);
        logo.setImageResource(R.drawable.ic_torongo_mark);
        header.addView(logo, new LinearLayout.LayoutParams(dp(46), dp(46)));

        LinearLayout titleBlock = new LinearLayout(this);
        titleBlock.setOrientation(LinearLayout.VERTICAL);
        titleBlock.setPadding(0, dp(6), 0, 0);
        LinearLayout.LayoutParams titleBlockParams = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1);
        titleBlockParams.leftMargin = dp(10);
        header.addView(titleBlock, titleBlockParams);

        TextView title = new TextView(this);
        title.setText("Torongo Verify");
        title.setTextSize(22);
        title.setTypeface(null, Typeface.BOLD);
        title.setTextColor(text);
        titleBlock.addView(title);

        TextView sub = new TextView(this);
        sub.setText("Official SMS sync for bKash, Nagad and Rocket.");
        sub.setTextSize(12);
        sub.setTextColor(muted);
        sub.setPadding(0, dp(2), 0, 0);
        titleBlock.addView(sub);
    }

    private void renderBottomNav() {
        bottomNav.removeAllViews();

        Button inbox = navButton("Inbox Sync", "sync");
        Button sms = navButton("SMS Sending", "sms");
        bottomNav.addView(inbox, weighted());
        bottomNav.addView(sms, weightedWithLeftMargin());
    }

    private Button navButton(String label, String tab) {
        Button button = compactButton(label, activeTab.equals(tab));
        button.setTextSize(12);
        button.setOnClickListener(v -> {
            activeTab = tab;
            renderBottomNav();
            renderSetup();
            renderActions();
            updateStatus();
        });
        return button;
    }

    private void renderSetup() {
        setupArea.removeAllViews();
        if (!"sync".equals(activeTab)) return;

        if (editingSetup) {
            renderSetupForm();
        } else {
            renderSavedSetup();
        }
    }

    private void renderSetupForm() {
        LinearLayout card = card();
        add(setupArea, card, 0, 0, 0, 10);

        TextView heading = heading("Setup device");
        card.addView(heading);

        server = input("Device URL", card);
        apiKey = input("Device API key", card);
        deviceName = input("Device name", card);
        bkash = box("bKash", card);
        nagad = box("Nagad", card);
        rocket = box("Rocket", card);

        loadFields();

        Button scanQr = button("Scan setup QR", card, true);
        Button save = button("Save setup", card, false);

        scanQr.setOnClickListener(v -> openQrScanner());
        save.setOnClickListener(v -> {
            if (!saveSetup()) return;
            editingSetup = false;
            renderSetup();
            renderActions();
            updateStatus();
            SyncClient.heartbeat(this);
            KeepAliveService.start(this);
            ensureBackgroundAccess();
            Toast.makeText(this, "Setup saved", Toast.LENGTH_SHORT).show();
        });
    }

    private void renderSavedSetup() {
        SharedPreferences prefs = prefs();
        LinearLayout card = card();
        add(setupArea, card, 0, 0, 0, 10);

        card.addView(heading("Saved setup"));
        card.addView(summaryLine("Device", prefs.getString("device_name", "Main Android Phone")));
        card.addView(summaryLine("Server", prefs.getString("server", "")));
        card.addView(summaryLine("API key", mask(prefs.getString("api_key", ""))));
        card.addView(summaryLine("Methods", methodsLabel(prefs.getString("methods", "bkash,nagad,rocket"))));
        card.addView(summaryLine("SIM routes", SimRouting.summary(this).replace("\n", " | ")));

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);
        add(card, row, 0, 4, 0, 0);

        Button edit = compactButton("Edit setup", false);
        Button delete = compactButton("Delete setup", false);
        row.addView(edit, weighted());
        row.addView(delete, weightedWithLeftMargin());

        edit.setOnClickListener(v -> {
            editingSetup = true;
            renderSetup();
            renderActions();
            updateStatus();
        });
        delete.setOnClickListener(v -> confirmDeleteSetup());
    }

    private void renderActions() {
        actionArea.removeAllViews();
        if (!hasSetup() || editingSetup) return;

        if ("sms".equals(activeTab)) {
            renderOutgoingSmsSection();
            return;
        }

        Button sync = button("Scan inbox + sync now", actionArea, true);
        Button sendQueuedSms = button("Send queued customer SMS", actionArea, false);
        Button battery = button("Battery optimization off", actionArea, false);
        renderIncomingSimRoutingSection(actionArea);

        sync.setOnClickListener(v -> {
            int scanned = InboxScanner.scanRecentOfficial(this);
            status.setText("Scanned recent official SMS: " + scanned + "\nSyncing queued SMS...");
            SyncClient.sync(this, (ok, message) -> {
                updateStatus();
                KeepAliveService.refresh(this);
                Toast.makeText(this, message, Toast.LENGTH_LONG).show();
                SyncClient.sendOutgoingSms(this);
            });
        });
        sendQueuedSms.setOnClickListener(v -> SyncClient.sendOutgoingSms(this, (ok, message) -> {
            updateStatus();
            Toast.makeText(this, message, Toast.LENGTH_LONG).show();
        }));
        battery.setOnClickListener(v -> startActivity(new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:" + getPackageName()))));
    }

    private void renderIncomingSimRoutingSection(LinearLayout parent) {
        LinearLayout card = card();
        add(parent, card, 0, 4, 0, 10);
        card.addView(heading("Incoming SMS routing"));

        if (!hasPhoneStatePermission()) {
            TextView note = smallText("Allow SIM permission to choose which account type each SIM controls.");
            add(card, note, 0, 0, 0, 8);
            Button allow = compactButton("Allow SIM detection", false);
            add(card, allow, 0, 0, 0, 8, dp(42));
            allow.setOnClickListener(v -> requestPermissions(new String[]{Manifest.permission.READ_PHONE_STATE}, 13));
            return;
        }

        List<SubscriptionInfo> sims = activeSubscriptions();
        if (sims.isEmpty()) {
            add(card, smallText("No active SIM detected."), 0, 0, 0, 0);
            return;
        }

        for (SubscriptionInfo sim : sims) {
            TextView label = smallText(simLabel(sim));
            label.setTypeface(null, Typeface.BOLD);
            add(card, label, 0, 6, 0, 4);
            addRouteBox(card, sim.getSubscriptionId(), "bkash", "send_money", "bKash Send Money");
            addRouteBox(card, sim.getSubscriptionId(), "bkash", "payment", "bKash Payment");
            addRouteBox(card, sim.getSubscriptionId(), "nagad", "send_money", "Nagad Send Money");
            addRouteBox(card, sim.getSubscriptionId(), "rocket", "send_money", "Rocket Send Money");
        }

        Button save = compactButton("Save SIM routing", true);
        add(card, save, 0, 8, 0, 0, dp(42));
        save.setOnClickListener(v -> {
            updateStatus();
            Toast.makeText(this, "SIM routing saved", Toast.LENGTH_SHORT).show();
        });
    }

    private void addRouteBox(LinearLayout parent, int subscriptionId, String method, String option, String label) {
        CheckBox item = box(label, parent);
        item.setChecked(SimRouting.isEnabled(this, subscriptionId, method, option));
        item.setOnCheckedChangeListener((buttonView, isChecked) -> {
            SimRouting.setEnabled(this, subscriptionId, method, option, isChecked);
            updateStatus();
            if (isChecked) renderActions();
        });
    }

    private void renderOutgoingSmsSection() {
        LinearLayout card = card();
        add(actionArea, card, 0, 0, 0, 10);
        card.addView(heading("Customer SMS"));

        outgoingSms = box("Send confirmation SMS from this phone", card);
        outgoingSms.setChecked(OutgoingSmsSender.enabled(this));
        outgoingSms.setOnCheckedChangeListener((buttonView, isChecked) -> {
            if (isChecked && !OutgoingSmsSender.hasPermission(this)) {
                requestPermissions(new String[]{Manifest.permission.SEND_SMS}, 12);
                outgoingSms.setChecked(false);
                Toast.makeText(this, "Allow SMS permission, then enable customer SMS again.", Toast.LENGTH_LONG).show();
                return;
            }
            OutgoingSmsSender.setEnabled(this, isChecked);
            updateStatus();
            if (isChecked) {
                SyncClient.sendOutgoingSms(this, (ok, message) -> {
                    updateStatus();
                    Toast.makeText(this, message, Toast.LENGTH_LONG).show();
                });
            }
        });

        renderSimSelection(card);

        TextView hint = new TextView(this);
        hint.setText("When on, Torongo Verify will fetch pending customer SMS from the server and send them using the selected SIM on this phone.");
        hint.setTextColor(muted);
        hint.setTextSize(11);
        hint.setLineSpacing(2, 1f);
        add(card, hint, 0, 0, 0, 0);
    }

    private void renderSimSelection(LinearLayout parent) {
        TextView title = heading("Sending SIM");
        title.setTextSize(14);
        add(parent, title, 0, 8, 0, 4);

        if (!hasPhoneStatePermission()) {
            TextView note = smallText("Allow SIM permission to choose which SIM sends customer confirmation SMS. Until then Android default SMS SIM will be used.");
            add(parent, note, 0, 0, 0, 8);
            Button allow = compactButton("Allow SIM detection", false);
            add(parent, allow, 0, 0, 0, 8, dp(42));
            allow.setOnClickListener(v -> requestPermissions(new String[]{Manifest.permission.READ_PHONE_STATE}, 13));
            return;
        }

        List<SubscriptionInfo> sims = activeSubscriptions();
        if (sims.isEmpty()) {
            TextView note = smallText("No active SIM detected. Android default SMS SIM will be used if available.");
            add(parent, note, 0, 0, 0, 8);
            return;
        }

        if (sims.size() == 1) {
            SubscriptionInfo sim = sims.get(0);
            OutgoingSmsSender.setSelectedSubscriptionId(this, sim.getSubscriptionId());
            TextView note = smallText("Selected: " + simLabel(sim) + " (only active SIM)");
            add(parent, note, 0, 0, 0, 8);
            return;
        }

        int selected = OutgoingSmsSender.selectedSubscriptionId(this);
        boolean selectedStillActive = false;
        for (SubscriptionInfo sim : sims) {
            if (sim.getSubscriptionId() == selected) {
                selectedStillActive = true;
                break;
            }
        }
        if (!selectedStillActive) {
            selected = sims.get(0).getSubscriptionId();
            OutgoingSmsSender.setSelectedSubscriptionId(this, selected);
        }

        for (SubscriptionInfo sim : sims) {
            boolean isSelected = sim.getSubscriptionId() == selected;
            Button simButton = compactButton((isSelected ? "Selected: " : "Use: ") + simLabel(sim), isSelected);
            add(parent, simButton, 0, 0, 0, 8, dp(42));
            simButton.setOnClickListener(v -> {
                OutgoingSmsSender.setSelectedSubscriptionId(this, sim.getSubscriptionId());
                renderActions();
                updateStatus();
                Toast.makeText(this, "Sending SIM selected: " + simLabel(sim), Toast.LENGTH_SHORT).show();
            });
        }
    }

    private boolean hasPhoneStatePermission() {
        return checkSelfPermission(Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED;
    }

    private List<SubscriptionInfo> activeSubscriptions() {
        try {
            SubscriptionManager manager = (SubscriptionManager) getSystemService(TELEPHONY_SUBSCRIPTION_SERVICE);
            List<SubscriptionInfo> sims = manager == null ? null : manager.getActiveSubscriptionInfoList();
            return sims == null ? java.util.Collections.emptyList() : sims;
        } catch (SecurityException exception) {
            return java.util.Collections.emptyList();
        }
    }

    private String simLabel(SubscriptionInfo sim) {
        CharSequence carrier = sim.getCarrierName();
        String carrierText = carrier == null || carrier.length() == 0 ? "SIM" : carrier.toString();
        return "SIM " + (sim.getSimSlotIndex() + 1) + " - " + carrierText;
    }

    private void openQrScanner() {
        if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.CAMERA}, 11);
            Toast.makeText(this, "Allow camera permission, then tap Scan setup QR again.", Toast.LENGTH_LONG).show();
            return;
        }
        try {
            IntentIntegrator integrator = new IntentIntegrator(this);
            integrator.setCaptureActivity(PortraitCaptureActivity.class);
            integrator.setPrompt("Scan Torongo Verify setup QR");
            integrator.setBeepEnabled(true);
            integrator.setOrientationLocked(true);
            integrator.initiateScan();
        } catch (Throwable exception) {
            Toast.makeText(this, "QR scanner could not open. Please rebuild and reinstall the app.", Toast.LENGTH_LONG).show();
        }
    }

    @Override protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        IntentResult result = IntentIntegrator.parseActivityResult(requestCode, resultCode, data);
        if (result != null) {
            if (result.getContents() == null) {
                Toast.makeText(this, "QR scan cancelled", Toast.LENGTH_SHORT).show();
                return;
            }
            applySetupQr(result.getContents());
            return;
        }
        super.onActivityResult(requestCode, resultCode, data);
    }

    private void applySetupQr(String raw) {
        try {
            JSONObject data = new JSONObject(raw);
            if (!"torongo_verify_setup".equals(data.optString("type"))) {
                Toast.makeText(this, "Invalid Torongo setup QR", Toast.LENGTH_LONG).show();
                return;
            }
            editingSetup = true;
            renderSetup();
            server.setText(data.optString("server", ""));
            apiKey.setText(data.optString("api_key", ""));
            deviceName.setText(data.optString("device_name", ""));
            String methods = data.optString("methods", "bkash,nagad,rocket");
            bkash.setChecked(methods.contains("bkash"));
            nagad.setChecked(methods.contains("nagad"));
            rocket.setChecked(methods.contains("rocket"));
            updateStatus();
            Toast.makeText(this, "Setup loaded. Tap Save setup.", Toast.LENGTH_LONG).show();
        } catch (Exception exception) {
            Toast.makeText(this, "Could not read setup QR", Toast.LENGTH_LONG).show();
        }
    }

    private boolean saveSetup() {
        String serverText = server.getText().toString().trim();
        String keyText = apiKey.getText().toString().trim();
        if (serverText.length() < 8 || keyText.length() < 8) {
            Toast.makeText(this, "Device URL and API key are required.", Toast.LENGTH_LONG).show();
            return false;
        }
        String methods = (bkash.isChecked() ? "bkash," : "") + (nagad.isChecked() ? "nagad," : "") + (rocket.isChecked() ? "rocket" : "");
        prefs().edit()
            .putString("server", serverText)
            .putString("api_key", keyText)
            .putString("device_name", deviceName.getText().toString().trim())
            .putString("methods", methods)
            .apply();
        return true;
    }

    private void confirmDeleteSetup() {
        new AlertDialog.Builder(this)
            .setTitle("Delete setup?")
            .setMessage("This will remove the saved device URL, API key and device name from this phone.")
            .setNegativeButton("Cancel", null)
            .setPositiveButton("Delete", (dialog, which) -> {
                prefs().edit().clear().apply();
                if (heartbeatHandler != null && heartbeatTask != null) {
                    heartbeatHandler.removeCallbacks(heartbeatTask);
                }
                editingSetup = true;
                renderSetup();
                renderActions();
                updateStatus();
                Toast.makeText(this, "Setup deleted", Toast.LENGTH_SHORT).show();
            })
            .show();
    }

    private void startHeartbeat() {
        heartbeatHandler = new Handler(Looper.getMainLooper());
        heartbeatTask = () -> {
            if (hasSetup() && !editingSetup) {
                InboxScanner.scanRecentOfficial(this);
                if (QueueStore.all(this).isEmpty()) {
                    SyncClient.heartbeat(this);
                } else {
                    SyncClient.sync(this);
                }
            }
            heartbeatHandler.postDelayed(heartbeatTask, 30000);
        };
        heartbeatHandler.post(heartbeatTask);
    }

    private void loadFields() {
        SharedPreferences p = prefs();
        server.setText(p.getString("server", ""));
        apiKey.setText(p.getString("api_key", ""));
        deviceName.setText(p.getString("device_name", ""));
        String methods = p.getString("methods", "bkash,nagad,rocket");
        bkash.setChecked(methods.contains("bkash"));
        nagad.setChecked(methods.contains("nagad"));
        rocket.setChecked(methods.contains("rocket"));
    }

    private void updateStatus() {
        String methods = editingSetup && bkash != null
            ? ((bkash.isChecked() ? "bKash " : "") + (nagad.isChecked() ? "Nagad " : "") + (rocket.isChecked() ? "Rocket" : ""))
            : methodsLabel(prefs().getString("methods", "bkash,nagad,rocket"));
        String serverText = editingSetup && server != null ? server.getText().toString().trim() : prefs().getString("server", "");
        int queued = QueueStore.count(this);
        status.setText("Queued SMS: " + queued
            + "\nAllowed methods: " + (methods.trim().isEmpty() ? "None selected" : methods.trim())
            + "\nSIM routes: " + SimRouting.summary(this).replace("\n", " | ")
            + "\nCustomer SMS sending: " + (OutgoingSmsSender.enabled(this) ? "On" : "Off")
            + "\nBattery optimization off recommended."
            + "\nServer: " + (serverText.isEmpty() ? "Not configured" : serverText));
        if (queueList != null) {
            queueList.setText("Pending queue\n" + QueueStore.preview(this, 4));
        }
    }

    private void requestCorePermissions() {
        java.util.ArrayList<String> permissions = new java.util.ArrayList<>();
        if (checkSelfPermission(Manifest.permission.RECEIVE_SMS) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.RECEIVE_SMS);
        }
        if (checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.READ_SMS);
        }
        if (checkSelfPermission(Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.SEND_SMS);
        }
        if (checkSelfPermission(Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.READ_PHONE_STATE);
        }
        if (checkSelfPermission(Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.CAMERA);
        }
        if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.POST_NOTIFICATIONS);
        }
        if (!permissions.isEmpty()) {
            requestPermissions(permissions.toArray(new String[0]), 10);
        }
    }

    private void ensureBackgroundAccess() {
        if (!hasSetup()) return;
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return;
        try {
            PowerManager powerManager = (PowerManager) getSystemService(POWER_SERVICE);
            if (powerManager == null || powerManager.isIgnoringBatteryOptimizations(getPackageName())) {
                return;
            }
            SharedPreferences prefs = prefs();
            long lastPrompt = prefs.getLong("battery_prompt_at", 0);
            long now = System.currentTimeMillis();
            if (now - lastPrompt < 24L * 60L * 60L * 1000L) {
                return;
            }
            prefs.edit().putLong("battery_prompt_at", now).apply();
            Intent intent = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
            intent.setData(Uri.parse("package:" + getPackageName()));
            startActivity(intent);
        } catch (Exception ignored) {}
    }

    private SharedPreferences prefs() {
        return getSharedPreferences("setup", MODE_PRIVATE);
    }

    private boolean hasSetup() {
        SharedPreferences p = prefs();
        return p.getString("server", "").length() >= 8 && p.getString("api_key", "").length() >= 8;
    }

    private String methodsLabel(String raw) {
        String label = "";
        if (raw.contains("bkash")) label += "bKash ";
        if (raw.contains("nagad")) label += "Nagad ";
        if (raw.contains("rocket")) label += "Rocket";
        return label.trim();
    }

    private String mask(String value) {
        if (value == null || value.length() <= 12) return value == null ? "" : value;
        return value.substring(0, 8) + "..." + value.substring(value.length() - 5);
    }

    private TextView heading(String value) {
        TextView view = new TextView(this);
        view.setText(value);
        view.setTextColor(text);
        view.setTextSize(15);
        view.setTypeface(null, Typeface.BOLD);
        view.setPadding(0, 0, 0, dp(7));
        return view;
    }

    private TextView summaryLine(String label, String value) {
        TextView view = new TextView(this);
        view.setText(label + ": " + (value == null || value.isEmpty() ? "-" : value));
        view.setTextColor(text);
        view.setTextSize(12);
        view.setSingleLine(true);
        view.setPadding(0, 0, 0, dp(6));
        return view;
    }

    private TextView smallText(String value) {
        TextView view = new TextView(this);
        view.setText(value);
        view.setTextColor(muted);
        view.setTextSize(11);
        view.setLineSpacing(2, 1f);
        return view;
    }

    private EditText input(String hint, LinearLayout parent) {
        TextView label = new TextView(this);
        label.setText(hint);
        label.setTextColor(Color.rgb(188, 211, 238));
        label.setTextSize(12);
        label.setTypeface(null, Typeface.BOLD);
        add(parent, label, 2, 0, 0, 4);

        EditText e = new EditText(this);
        e.setHint(hint);
        e.setHintTextColor(Color.rgb(105, 130, 158));
        e.setTextColor(text);
        e.setTextSize(13);
        e.setSingleLine(true);
        e.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD);
        e.setPadding(dp(18), 0, dp(18), 0);
        e.setBackgroundColor(field);
        add(parent, e, 0, 0, 0, 8, dp(44));
        return e;
    }

    private CheckBox box(String label, LinearLayout parent) {
        CheckBox c = new CheckBox(this);
        c.setText(label);
        c.setTextColor(text);
        c.setTextSize(14);
        c.setButtonTintList(android.content.res.ColorStateList.valueOf(accent));
        c.setChecked(true);
        add(parent, c, 0, 0, 0, 4);
        return c;
    }

    private Button button(String label, LinearLayout parent, boolean primary) {
        Button b = compactButton(label, primary);
        add(parent, b, 0, 0, 0, 8, dp(44));
        return b;
    }

    private Button compactButton(String label, boolean primary) {
        Button b = new Button(this);
        b.setText(label);
        b.setAllCaps(false);
        b.setTextSize(13);
        b.setTypeface(null, Typeface.BOLD);
        b.setTextColor(primary ? Color.rgb(3, 15, 28) : text);
        b.setBackgroundColor(primary ? accent : Color.rgb(19, 38, 61));
        return b;
    }

    private LinearLayout card() {
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(dp(14), dp(14), dp(14), dp(14));
        layout.setBackgroundColor(panel);
        return layout;
    }

    private LinearLayout.LayoutParams matchWrap() {
        return new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
    }

    private LinearLayout.LayoutParams weighted() {
        return new LinearLayout.LayoutParams(0, dp(40), 1);
    }

    private LinearLayout.LayoutParams weightedWithLeftMargin() {
        LinearLayout.LayoutParams params = weighted();
        params.leftMargin = dp(10);
        return params;
    }

    private void add(LinearLayout parent, View view, int l, int t, int r, int b) {
        add(parent, view, l, t, r, b, LinearLayout.LayoutParams.WRAP_CONTENT);
    }

    private void add(LinearLayout parent, View view, int l, int t, int r, int b, int h) {
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, h);
        params.setMargins(dp(l), dp(t), dp(r), dp(b));
        parent.addView(view, params);
    }

    private int dp(int value) {
        return (int) (value * getResources().getDisplayMetrics().density + 0.5f);
    }
}
