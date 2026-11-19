-- Migration: normalise all status column values to lowercase
-- Run once against the production database.
-- Safe to re-run (LOWER of an already-lowercase value is a no-op).

-- otrs_registrations: payment_status
UPDATE otrs_registrations
SET payment_status = LOWER(payment_status)
WHERE payment_status REGEXP '[A-Z]';

-- otrs_registrations: registration_status
UPDATE otrs_registrations
SET registration_status = LOWER(registration_status)
WHERE registration_status REGEXP '[A-Z]';

-- otrs_registrations: training_status
UPDATE otrs_registrations
SET training_status = LOWER(training_status)
WHERE training_status IS NOT NULL AND training_status REGEXP '[A-Z]';

-- otrs_trainings: training_status  (Upcoming / Ongoing / Completed / Cancelled)
UPDATE otrs_trainings
SET training_status = LOWER(training_status)
WHERE training_status REGEXP '[A-Z]';

-- otrs_trainings: ensure id has AUTO_INCREMENT (safe no-op if already set)
ALTER TABLE `otrs_trainings`
  MODIFY `id` int(12) NOT NULL AUTO_INCREMENT;

-- Fix the column default to match the new lowercase convention
ALTER TABLE `otrs_trainings`
  ALTER COLUMN `training_status` SET DEFAULT 'upcoming';
