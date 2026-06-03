# Google Sheet Backup Setup

Ei backup website database-er replacement na. Eta emergency copy: website down, hack, ba database delete holeo Google Sheet-e transaction/event copy thakbe.

## Sheet Create

1. Google Drive-e ekta blank Google Sheet create korun.
2. Sheet-er naam dite paren: `Torongo Pay Backup`.
3. Extensions > Apps Script open korun.
4. Ei repo-r `docs/google-sheet-backup-webhook.gs` file-er full code paste korun.
5. Script-er first line-e `SHEET_SECRET = 'CHANGE_THIS_SECRET'` value change kore nijer private secret din.

## Deploy Apps Script

1. Apps Script page theke Deploy > New deployment.
2. Type select korun: Web app.
3. Execute as: Me.
4. Who has access: Anyone.
5. Deploy kore Web app URL copy korun.

## Admin Setup

Admin > Settings > Google Sheet Backup:

- Google Sheet webhook URL: Apps Script Web app URL paste korun.
- Google Sheet secret: Apps Script-er `SHEET_SECRET` value paste korun.

Save korar por new invoice create/payment verify/manual approve/reject event hole Google Sheet auto update hobe.

## Sheet Tabs

Script automatically ei tabs create/format korbe:

- Dashboard
- Successful Payments
- Pending Payments
- Failed & Expired
- All Events
- Account Balances
- SMS Logs

`Successful Payments` tab append-only. Successful payment row edit/update kora hoy na, jate eta log data-r moto thake. Pending and failed/expired tabs invoice ID diye latest snapshot update kore. `All Events` tab sob webhook event append kore.

## Important

- Secret publicly share korben na.
- Same Sheet URL/secret production website-e use korben.
- Jodi Apps Script URL change hoy, Admin Settings-e notun URL update korte hobe.
