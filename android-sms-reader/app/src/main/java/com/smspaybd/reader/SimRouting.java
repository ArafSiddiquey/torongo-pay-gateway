package com.smspaybd.reader;

import android.content.Context;
import android.content.SharedPreferences;
import android.telephony.SubscriptionInfo;
import org.json.JSONObject;
import java.util.Iterator;

public class SimRouting {
    private static final String KEY = "sim_routes";

    public static class Route {
        public final String method;
        public final String option;

        Route(String method, String option) {
            this.method = method;
            this.option = option;
        }
    }

    public static Route routeFor(Context context, int subscriptionId, String sender, String body) {
        String method = detectMethod(sender, body);
        if (method.length() == 0) return new Route("", "");

        String saved = prefs(context).getString(KEY, "{}");
        try {
            JSONObject all = new JSONObject(saved);
            JSONObject routes = all.optJSONObject(String.valueOf(subscriptionId));
            if (routes == null) return new Route(method, "");
            return new Route(method, routes.optString(method, ""));
        } catch (Exception ignored) {
            return new Route(method, "");
        }
    }

    public static boolean isEnabled(Context context, int subscriptionId, String method, String option) {
        try {
            JSONObject all = new JSONObject(prefs(context).getString(KEY, "{}"));
            JSONObject routes = all.optJSONObject(String.valueOf(subscriptionId));
            return option.equals(routes == null ? "" : routes.optString(method, ""));
        } catch (Exception ignored) {
            return false;
        }
    }

    public static void setEnabled(Context context, int subscriptionId, String method, String option, boolean enabled) {
        try {
            JSONObject all = new JSONObject(prefs(context).getString(KEY, "{}"));
            JSONObject routes = all.optJSONObject(String.valueOf(subscriptionId));
            if (routes == null) routes = new JSONObject();
            if (enabled) {
                routes.put(method, option);
            } else if (option.equals(routes.optString(method, ""))) {
                routes.remove(method);
            }
            all.put(String.valueOf(subscriptionId), routes);
            prefs(context).edit().putString(KEY, all.toString()).apply();
        } catch (Exception ignored) {}
    }

    public static String summary(Context context) {
        try {
            JSONObject all = new JSONObject(prefs(context).getString(KEY, "{}"));
            StringBuilder builder = new StringBuilder();
            Iterator<String> keys = all.keys();
            while (keys.hasNext()) {
                String subId = keys.next();
                JSONObject routes = all.optJSONObject(subId);
                if (routes == null || routes.length() == 0) continue;
                if (builder.length() > 0) builder.append("\n");
                builder.append("Sub ").append(subId).append(": ");
                Iterator<String> methods = routes.keys();
                boolean first = true;
                while (methods.hasNext()) {
                    String method = methods.next();
                    if (!first) builder.append(", ");
                    builder.append(method).append(" ").append(routes.optString(method));
                    first = false;
                }
            }
            return builder.length() == 0 ? "No SIM routing selected." : builder.toString();
        } catch (Exception ignored) {
            return "No SIM routing selected.";
        }
    }

    public static String simLabel(SubscriptionInfo sim) {
        CharSequence carrier = sim.getCarrierName();
        String carrierText = carrier == null || carrier.length() == 0 ? "SIM" : carrier.toString();
        return "SIM " + (sim.getSimSlotIndex() + 1) + " - " + carrierText;
    }

    private static String detectMethod(String sender, String body) {
        String text = ((sender == null ? "" : sender) + " " + (body == null ? "" : body)).toLowerCase(java.util.Locale.US);
        if (text.contains("bkash")) return "bkash";
        if (text.contains("nagad")) return "nagad";
        if (text.contains("rocket") || text.contains("dbbl") || text.contains("16216")) return "rocket";
        return "";
    }

    private static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences("setup", Context.MODE_PRIVATE);
    }
}
