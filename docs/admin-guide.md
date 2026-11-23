# ORK Administrator Guide

## What global admin is

A global ORK administrator passes every permission check in the system. The ORK asks "is this person a global admin?" before it looks at any park, kingdom, event or unit permission, and a yes ends the check.

That means an admin can edit any player, award, attendance record, park, kingdom, event or unit, in every kingdom, without holding an office there.

A few things are open only to global admins:

- Creating, retiring and restoring kingdoms
- Transferring a park from one kingdom to another
- Granting and revoking global admin, on the System Administrators page
- Many system-wide reports
- Access to the Audit Log report

The role exists to fix what kingdom and park officers cannot fix themselves, and to keep the ORK running.

## The core principle

Before acting on something, ask a few questions:

1. **Is this mine to do?** If a kingdom or park officer can do it, point the request to them. Use it as a teaching moment if they do not know how.
2. **Was I asked?** Changes in another kingdom should come from a request by that kingdom's officers or the player concerned.
3. **Can it be undone?** If not, slow down and read the high-impact section below.

## Do

- **Work from a request, and log it.** After an admin action that is worthy of keeping track of, post a one-line note in the #ork-admin-log channel on the ORK Discord server: what you changed, where, and who asked. Only ORK admins can see that channel. That gives an answer when someone later asks why a record changed. Leave out private details such as legal names and email addresses.
- **Confirm identity before touching an account.** For email changes, password resets and merges, confirm the request really comes from the player or their officers.
- **Check the record before and after.** Open the player, park or kingdom first, make the change, then look again to confirm it did what you meant.
- **Use your own named account.** Every change is logged against the account that made it. Never use the shared admin or orkadmin accounts to make changes: a change made there cannot be traced to a person.
- **Hand local work back.** If you are asked to do something a kingdom or park officer can do, show them how, or make sure they hold the right permission.
- **Report anything odd.** Unexpected permissions, records that look tampered with, or errors during an admin action should be raised in #ork-administrative-chat.
- **Give the role back when you no longer need it.** Stepping down as admin does not affect your player record or any office you hold. Being an Admin can often take a lot of time. There's nothing wrong with giving it up for a while.

## Don't

- **Don't be the friendly do-whatever admin.** If someone really needs help, sure, but don't become the admin a park or kingdom turns to instead of its own officers learning the system.
- **Don't use admin as a shortcut around an office.** Awards, attendance and dues belong to the kingdom and park officers responsible for them.
- **Don't grant permissions as a favor.** Certainly not Admin rights. Using the Roles & Permissions to grant permissions needs to be checked with the right authorities. Kingdom, Park, Event, etc.
- **Don't share your login.** It goes without saying.
- **Don't browse private details that don't pertain to the task.** Admins can see legal names, email addresses, etc. Look only at what the task requires.

## Helping the community

Part of the role is helping other people understand the ORK. Most questions come in on the ORK Discord server, and some still come in on the [ORK Updates Facebook group](https://www.facebook.com/groups/orkupdates). Admins are expected to keep an eye on both and answer when they can. There is no expected response time: admins are all volunteers and respond when able.

**Discord channels to watch**

| Channel | What shows up there | What to do |
| --- | --- | --- |
| #general-discussion | General talk about the ORK, with questions mixed in. | Answer questions. If one turns into a problem report, point the person to #help-and-troubleshooting. |
| #help-and-troubleshooting | "How do I…" and "this is not working for me". | Walk the person through it. If the fix belongs to their park or kingdom officers, say which officer and what to ask for. |
| #bug-reports-orkwide | Reports that something in the ORK is broken. | Try to reproduce it. Ask for the page, the steps and a screenshot if they are missing. Say whether you could confirm it. |
| #feature-requests | Ideas for new features or changes. | Ask what problem the person is trying to solve. Mention it if the ORK already does it. Do not promise that it will be built or when. |

Anyone can open an issue on the ORK3 repository on GitHub for a confirmed bug or a feature request. Whether and when a fix or feature is built is at the discretion of the ORK development team.

**When answering**

- **Teach, don't just fix.** Explain where the page is and who can use it, so the person or their officers can do it next time.
- **Say when you have picked something up.** A short reply such as "looking at this" stops two admins working on the same request.
- **Close the loop.** Reply in the same thread when it is done. If you changed a record, also post the note in #ork-admin-log.
- **Keep private details out of public channels.** Watch out for reports or pages where your Admin rights may print more information that should be shared publicly. Mundane names and other details could be shared in your screenshots.
- **Admit when you don't know.** Ask in #ork-administrative-chat and come back with the answer.
- **Check Facebook too.** Answer questions posted on the Facebook group there, and let people know the Discord server is where most help now happens.

## High-impact actions

These actions affect many records at once or cannot be undone from the ORK itself. Get a second admin or the affected kingdom's officers to agree before doing any of them.

| Action | What it does | Check first |
| --- | --- | --- |
| Merge players | Folds one player record into another and deletes the source record. There is no un-merge. | Both records are the same person, confirmed by the player or their park. You are keeping the right one. |
| Transfer a park | Moves the park to another kingdom, along with every player and officer in it. | Both kingdoms have agreed. The date and the announcement are settled. |
| Suspend or unsuspend a player | Changes a person's standing, and can carry across kingdoms. | The decision was made by the people entitled to make it. You are recording it, not making it. |
| Grant or remove permissions | Changes who can edit records for a park, kingdom, event or unit. | The person, the scope and the level are all correct. See the next section. |
| Create, retire or restore a kingdom | Adds or removes a kingdom for every user of the ORK. | The decision is official. Nobody else has already done it. |
| Change kingdom settings | Changes rules and configuration for every park in that kingdom. | That kingdom's officers asked for it. You noted the old values. |
| Remove attendance or awards in bulk | Deletes history that players rely on. Putting it back is manual. | You have a list of exactly what is being removed and why. Test on one record first. |

If something goes wrong during one of these, stop. Do not try to repair it with further changes. Write down what you did and raise it straight away.

## Granting and reviewing access

Every permission you grant is one more account that can change records. Grant the least that works.

**When granting any permission**

- Pick the narrowest scope: park before kingdom, kingdom before global.
- Pick the lowest level that does the job.
- Officer permissions come with the office. Set or vacate the officer; do not add or delete the permission by hand.

**When granting global admin**

- Do not add Global Admins without a good use case. Run it by other Admins in the #ork-administrative-chat on Discord.
- The new Admin needs to be on the ORK Discord, and be given the ORK Administrator role.
- If the person's work is all inside one kingdom, do not grant global admin. Grant a permission through that kingdom's Roles & Permissions instead.

**Reviewing the list**

- Proposed rule: review the System Administrators page twice a year.
- Remove global admin from anyone who has not used it in 12 months, and from anyone who has left the role that needed it. It can be granted again in a minute if they return.
- Move admins whose work is all in one kingdom to a permission in that kingdom's Roles & Permissions, and remove their global admin.
- The "Last Seen" column on that page is the last day the account did anything signed in, on the site or in an app. It is the measure to use for the 12-month rule.

## Accounts and security

A global admin login is the most valuable account in the ORK. Compromised officer accounts have been used to edit players in other kingdoms before; an admin account would allow far more.

- Use a password that is unique to the ORK, kept in a password manager.
- Log out when you finish on any device you do not own. A session otherwise stays valid for about 30 days.
- If you think your account was used by someone else, change the password and tell the other admins at once, so recent changes can be checked.

## What is logged

The ORK keeps an audit log of changes: who made the change, when, which record, and for many actions the values before and after. It is reviewed, and it is how questions about a changed record get answered.

- **Logged:** edits to players, awards, attendance, dues, parks, kingdoms, officers, units, event staff, merges, suspensions and permission changes.
- **Not recorded:** which permission allowed a change. The log cannot tell whether you acted as an admin or as an officer, so the reason has to come from you.
- **Coverage is uneven before mid-2026.** Player, award and attendance changes go back to 2016. Permission changes have only been logged since May 2026.

The practical upshot: assume any change you make can be traced to your account, and post a note in #ork-admin-log saying why.

## When unsure

Ask before acting. A question costs a few minutes; a wrong merge or transfer costs hours and someone's trust.

- Not sure whether a request is legitimate: confirm with the kingdom or park Prime Minister or Monarch.
- Not sure how a feature behaves: ask in #ork-administrative-chat.
- Something looks broken or tampered with: stop, note what you saw and when, and report it in #ork-administrative-chat.
