package com.smspaybd.reader;

import android.animation.ObjectAnimator;
import android.os.Bundle;
import android.view.View;
import android.view.animation.AccelerateDecelerateInterpolator;

import com.journeyapps.barcodescanner.CaptureActivity;

public class PortraitCaptureActivity extends CaptureActivity {
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        View line = findViewById(R.id.scanLine);
        if (line != null) {
            ObjectAnimator animator = ObjectAnimator.ofFloat(line, "translationY", -118f, 118f);
            animator.setDuration(1400);
            animator.setRepeatMode(ObjectAnimator.REVERSE);
            animator.setRepeatCount(ObjectAnimator.INFINITE);
            animator.setInterpolator(new AccelerateDecelerateInterpolator());
            animator.start();
        }
    }
}
