package com.smspaybd.reader;

import java.util.Locale;

public class OfficialSmsParser {
    private static final String[] BKASH_SENDERS = {"bkash"};
    private static final String[] NAGAD_SENDERS = {"nagad"};
    private static final String[] ROCKET_SENDERS = {"rocket", "dbbl", "16216"};

    public static boolean isOfficial(String sender, String body, String allowedMethodsCsv) {
        String method = detectMethod(sender);
        if (method == null) return false;

        String allowed = allowedMethodsCsv == null ? "" : allowedMethodsCsv.toLowerCase(Locale.US);
        if (!allowed.contains(method)) return false;

        return looksRelevantPaymentSms(body);
    }

    public static String detectMethod(String sender) {
        String normalized = normalize(sender);
        if (contains(BKASH_SENDERS, normalized)) return "bkash";
        if (contains(NAGAD_SENDERS, normalized)) return "nagad";
        if (contains(ROCKET_SENDERS, normalized)) return "rocket";
        return null;
    }

    private static boolean contains(String[] values, String needle) {
        for (String value : values) {
            if (value.equals(needle)) return true;
        }
        return false;
    }

    private static boolean looksRelevantPaymentSms(String body) {
        String text = body == null ? "" : body.toLowerCase(Locale.US);

        if (text.matches(".*\\bpayment\\s+(tk|bdt)\\s*[0-9,]+(\\.[0-9]{1,2})?\\s+to\\b.*")
            || text.contains("send money to") || text.contains("payment to") || text.contains("sent to")
            || text.contains("cash out") || text.contains("debited") || text.contains("withdraw")) {
            return text.contains("trxid") || text.contains("trx id") || text.contains("txnid");
        }

        return text.contains("received") || text.contains("payment received") || text.contains("cash in")
            || text.contains("credited") || text.contains("has been received");
    }

    private static String normalize(String value) {
        if (value == null) return "";
        return value.trim().toLowerCase(Locale.US).replaceAll("[^a-z0-9]", "");
    }
}
