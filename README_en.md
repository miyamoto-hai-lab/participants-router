# participants-router

[日本語](README.md) | **English**

&emsp;&emsp;
[![Tests](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml/badge.svg)](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml)
[![Coverage Status](https://coveralls.io/repos/github/miyamoto-hai-lab/participants-router/badge.svg?branch=main)](https://coveralls.io/github/miyamoto-hai-lab/participants-router?branch=main)

**participants-router** is a backend routing system built with PHP, designed for psychological experiments and online surveys.
It assigns unique experimental conditions to each participant and manages transitions to multiple experimental steps (consent forms, tasks, questionnaires, etc.).

## Features

- **Single URL Distribution**: Simply distribute the same URL (entry point) to all participants, and they will be automatically directed to condition-specific URLs.
- **Duplicate Participation Prevention**: Manages participation status using browser IDs or similar keys to prevent and control duplicate participation.
- **Flexible Assignment Strategies**: Supports Minimal Group Assignment (minimizing participant counts per condition) and random assignment.
- **Access Control**: Advanced participation conditions (screening) can be set using regular expressions or integration with external APIs (such as CrowdWorks).
- **Heartbeat Monitoring**: Provides a heartbeat API to detect participant dropouts.
- **Stateful Progress Management**: Manages which step a participant is currently in via a database, allowing them to resume from the correct position even after reloading or re-accessing.

## Requirements

- **Web Server**: Apache, Nginx, etc.
- **PHP**: 8.3 or higher recommended
- **Database**: MySQL, PostgreSQL, SQLite (PDO-compatible DB)
- **Composer**: PHP Package Manager (https://getcomposer.org/)

## Installation

1. **Clone the Repository**
   ```shell
   git clone https://github.com/miyamoto-hai-lab/participants-router.git
   cd participants-router
   ```

2. **Install Dependencies**
   Install dependent packages using [Composer](https://getcomposer.org/).
   ```shell
   composer install
   ```

3. **Database Configuration**
   You only need to configure `config.jsonc`.
   The necessary tables (default: `participants_routes`) will be automatically created when the application starts.
   
   If using SQLite, the database file will be automatically created at the specified path if it does not exist.

4. **Edit Configuration File**
   Edit `config.jsonc` to match your environment.
   Describe database connection information and experiment settings.

   Editing with [Visual Studio Code](https://code.visualstudio.com/) or similar editors will display setting descriptions based on `config.schema.json`.

5. **Deploy to Web Server**
   Place all files, including the `vendor` directory generated in Step 2, in the web server's public directory (document root) or a location accessible from it.

   Upon initial access to the API, the necessary tables (default: `participants_routes`) will be automatically created.
   If using SQLite, the database file will be automatically created at the specified path if it does not exist.


## Configuration (`config.jsonc`)

The configuration file is written in JSONC (JSON with Comments) format. The main settings are as follows.

### Basic Settings

```jsonc
{
    "$schema": "./config.schema.json",
    // API base path (e.g., "/api/router")
    "base_path": "/api/router",

    // Database connection settings
    "database": {
        "url": "mysql://user:pass@localhost/dbname", // or sqlite://./db.sqlite
        "table": "participants_routes"
    },

    "experiments": {
        // Experiment ID (used in API requests)
        "sample_experiment": {
            "enable": true, // Stop access if set to false
            "config": { ... } // Detailed settings for each experiment
        }
    }
}
```

### Experiment Settings (`config`) Details

| Key | Description |
| :--- | :--- |
| `access_control` | Participation condition (screening) rules. Regex and external API integration are possible. |
| `assignment_strategy` | Assignment strategy. `minimum` (assign to condition with fewest participants) or `random`. |
| `fallback_url` | URL to redirect to when full or when the experiment is invalid. |
| `heartbeat_intervalsec` | Time frame (seconds) to count as a valid participant. Participants without a heartbeat within this time may be considered "dropped out" and excluded from the count. |
| `groups` | Definition of experimental conditions (groups). |

#### Access Control Settings

`access_control` is a feature to restrict users who can participate in the experiment. It is defined as a **logical condition tree** combining `all_of` (AND), `any_of` (OR), and `not` (NOT).

**Logical Operators:**

| Key | Description |
| :--- | :--- |
| `all_of` | Returns `true` if **all** conditions in the list are `true` (AND). |
| `any_of` | Returns `true` if **any** condition in the list is `true` (OR). |
| `not` | **Inverts** the truth value of the specified condition (NOT). |

**Rules (Leaf Conditions):**

Describe one of the following rules at the leaves of logical operators.

**1. Regex Check (`type: regex`)**
Checks the value of `properties` sent from the client against a regular expression.
If `field` is specified as `participant_id`, it checks the value of `participant_id` against the pattern.

```jsonc
{
    "type": "regex",
    "field": "age",       // Property name to check
    "pattern": "^2[0-9]$" // Regex pattern (e.g., 20s)
}
```

**2. External API Query (`type: fetch`)**
Sends an HTTP request to an external server and makes a decision based on the result.
Placeholders in the format `${keyname}` can be used in the URL, header, or Body, and will be replaced with values from `properties`.
If `keyname` is `participant_id`, it will be replaced with the value of `participant_id`.

```jsonc
{
    "type": "fetch",
    "url": "https://api.example.com/check?id=${participant_id}",
    "method": "GET", // GET (default) or POST
    // "headers": { "Authorization": "Bearer ..." },
    // "body": { "id": "${participant_id}" }, // For POST
    "expected_status": 200 // HTTP status considered success (default is 200 OK range)
}
```

**Configuration Example: Complex Condition**

Example allowing participation only if "CrowdWorks ID is a number of 6 or more digits" **AND** "Duplicate check via external API is OK (returns 200, so invert with `not` to make it false for rejection)":

```jsonc
"access_control": {
    "condition": {
        "all_of": [
            {
                "type": "regex",
                "field": "participant_id",
                "pattern": "^\\d{6,}$"
            },
            {
                "not": { 
                    "type": "fetch",
                    "url": "https://api.example.com/check_duplicate/${participant_id}",
                    "expected_status": 200 // If duplicate exists (200) -> true -> not makes it false (deny)
                }
            }
        ]
    },
    "action": "allow", // Action when condition is true (currently only allow)
    "deny_redirect": "https://example.com/denied.html" // Destination if denied
}
```

#### Groups (Conditions/Steps) Settings

Define the experiment progress (steps) as a list of URLs. When a user sends a "Next" request from the current URL, the next URL in the list is returned.

```jsonc
"groups": {
    "group_A": {
        "limit": 50, // Participant limit
        "steps": [
            // STEP 1
            "https://survey.example.com/consent", 
            // STEP 2
            "https://task.example.com/task_A",
            // STEP 3
            "https://survey.example.com/post_survey"
        ]
    },
    "group_B": {
        "limit": 50,
        "steps": [
            "https://survey.example.com/consent",
            "https://task.example.com/task_B", // Task different from group_A
            "https://survey.example.com/post_survey"
        ]
    }
}
```

## API Specification

Clients (such as frontend apps for conducting experiments) mainly use the following three APIs. All responses are in JSON format.

### 1. Assign (Assign)

Registers participation in the experiment and retrieves the URL for the first step.

- **Endpoint**: `POST /router/assign`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "sample_experiment",
  "participant_id": "unique_participant_id_abc123", // ID uniquely identifying the experiment participant
  "properties": {
    // Attributes used for access_control, etc.
    "browser_id": "019ba8d6-748e-70ae-bdf0-b29fc9188782", // Browser-specific ID (see below)
    "age": 25
  }
}
```

> [!TIP]
> **About browser_id**
>
> In crowdsourcing experiments, it is recommended to set `browser_id` in properties to prevent double participation with different accounts.
> `browser_id` is a browser-specific ID that can be restored when re-accessed from the same browser.
> IDs that change every time the experiment page is accessed, like session IDs, cannot be used for `browser_id` because the experiment cannot be resumed from the middle upon re-access.
>
> For ID generation and management, we recommend using the **[browser-id](https://github.com/miyamoto-hai-lab/browser-id)** library developed by the Miyamoto Laboratory. This makes it easy to appropriately persist to local storage and generate browser-specific IDs.
>
> See also [Example](#examples) for more details.

**Response (Success):**
```jsonc
{
  "status": "ok",
  "url": "https://survey.example.com/consent", // URL to redirect to
  "message": null
}
```

**Response (Denied/Full/Error):**
```jsonc
{
  "status": "ok", // or "error"
  "url": "https://example.com/sorry.html", // Redirect destination (if configured)
  "message": "Access denied" // or "Full", etc.
}
```

### 2. Next Step (Next)

Completes the current step and retrieves the URL for the next step. The system determines progress based on the current URL (`current_url`).

- **Endpoint**: `POST /router/next`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "sample_experiment",
  "participant_id": "unique_participant_id_abc123",
  "current_url": "https://survey.example.com/consent?user=123", // Currently displayed URL
  "properties": {
      "score": 100 // Properties can be updated if necessary
  }
}
```

**Response (Next Step):**
```jsonc
{
  "status": "ok",
  "url": "https://task.example.com/task_A", // Next URL
  "message": null
}
```

**Response (Completed):**
```jsonc
{
  "status": "ok",
  "url": null, // null if there is no next step
  "message": "Experiment completed"
}
```

### 3. Heartbeat (Heartbeat)

Notifies that the participant is continuing the experiment (has the browser open). Used in conjunction with the `heartbeat_intervalsec` setting to accurately track the number of active participants.

- **Endpoint**: `POST /router/heartbeat`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "sample_experiment",
  "participant_id": "unique_participant_id_abc123"
}
```

**Response:**
```jsonc
{
  "status": "ok"
}
```

## Examples
### participants-router Configuration Example
Configuration example including participation restriction by browser_id
```jsonc
{
    "$schema": "./config.schema.json",
    "base_path": "",
    "database": {
        "url": "mysql://user:pass@localhost/dbname",
        "table": "participants_routes"
    },
    "experiments": {
        "sample_experiment": {
            "enable": true,
            "config": {
                "access_control": {
                    "condition": {
                        "all_of": [
                            {
                                "type": "regex",
                                "field": "participant_id",
                                "pattern": "^\\d{6,}$"
                            },
                            {
                                "not": { 
                                    "type": "fetch",
                                    "url": "https://api.example.com/check_duplicate/sample_experiment",
                                    "method": "POST",
                                    "headers": {
                                        "Content-Type": "application/json",
                                        "Authorization": "Bearer ${token}"
                                    },
                                    "body": {
                                        "participant_id": "${participant_id}",
                                        "browser_id": "${browser_id}"
                                    },
                                    "expected_status": 200
                                }
                            }
                        ]
                    },
                    "action": "allow",
                    "deny_redirect": "https://example.com/denied.html"
                },
                "assignment_strategy": "minimum",
                "fallback_url": "https://example.com/fallback.html",
                "heartbeat_intervalsec": 60,
                "groups": {
                    "group_a": {
                        "size": 10,
                        "url": "https://task.example.com/task_A"
                    },
                    "group_b": {
                        "size": 10,
                        "url": "https://task.example.com/task_B"
                    }
                }
            }
        }
    }
}
```
You can achieve participation restrictions by combining fetch condition and browser_id as follows:
```jsonc
"not": { 
    "type": "fetch",
    "url": "https://api.example.com/check_duplicate/sample_experiment",
    "method": "POST",
    "headers": {
        "Content-Type": "application/json",
        "Authorization": "Bearer ${token}"
    },
    "body": {
        "participant_id": "${participant_id}", // Participant ID
        "browser_id": "${browser_id}" // Browser ID
    },
    "expected_status": 200
}
```

### Client Implementation Example (jsPsych)

An implementation example combining the [browser-id](https://github.com/miyamoto-hai-lab/browser-id) library and [jsPsych](https://www.jspsych.org/).

#### 1. Initial Assignment (Assign)

Obtain (generate) `participant_id` on the first screen and call the `Assign` API to transition to the experiment URL.

```javascript
// Load the browser-id library in the html body, etc.
// <script src="browser-id.global.js"></script>

const APP_NAME = "sample_experiment";

// Example defined as a jsPsych trial
const loading_process_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: `<div class="loader"></div><p>Redirecting to experiment page...</p>`,
    choices: "NO_KEYS",
    on_load: async () => {
        try {
            // 1. Initialize browser-id
            const browser = new BrowserIdLib.AsyncBrowser(
                APP_NAME,
                undefined, 
                // ID validation function
                (id) => typeof id === "string" && id.length > 0
            );

            // 2. Get browser_id (Generated first time, retrieved from LocalStorage thereafter)
            const browserId = await browser.get_id();

            // 3. Save attribute information (if necessary)
            // Example: Get ID entered in the previous trial
            const cwid = jsPsych.data.get().last(1).values()[0].response.cwid;
            await browser.set_attribute("participant_id", cwid);

            // 4. Participation request to Server (Assign)
            const response = await fetch('/api/router/assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    participant_id: cwid,
                    properties: {
                        // Send browser_id as well to detect multiple participation with multiple accounts
                        browser_id: browserId
                    }
                })
            });

            if (!response.ok) throw new Error('Network response was not ok');
            const result = await response.json();

            // 5. Redirect to destination URL
            if (result.data.url) {
                window.location.href = result.data.url + "?cwid=" + cwid;
            } else {
                alert("Could not participate: " + (result.data.message || "Unknown error"));
            }

        } catch (e) {
            console.error(e);
            alert("An error occurred");
        }
    }
};
```

#### 2. Heartbeat and Page Transition (Heartbeat & Next)

On each page during the experiment, send a heartbeat periodically, and call the `Next` API at the end of the task to proceed to the next step.

```javascript
// Start Heartbeat upon page load
const browser = new BrowserIdLib.AsyncBrowser(APP_NAME, /* ... */);

const cwid = window.location.searchParams.get("cwid");

document.addEventListener("DOMContentLoaded", async () => {
    // Send heartbeat every 10 seconds
    if (cwid) {
        setInterval(() => {
            fetch("/api/router/heartbeat", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    participant_id: cwid
                })
            }).catch(e => console.error("Heartbeat error:", e));
        }, 10000);
    }
});

// Process to proceed to next (jsPsych trial, etc.)
const next_step_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: "Processing...",
    on_load: async () => {
        const currentUrl = window.location.href;

        // Call Next API
        const response = await fetch('/api/router/next', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                experiment_id: APP_NAME,
                participant_id: cwid,
                current_url: currentUrl,
                properties: {
                    // If branching by score, etc.
                    // score: 100
                }
            })
        });

        const result = await response.json();
        if (result.data.url) {
            window.location.href = result.data.url + "?cwid=" + cwid;
        } else {
            alert("Experiment completed. Thank you.");
        }
    }
};
```

## Directory Structure / Developer Info

For detailed directory structure and database schema, please refer to [CONTRIBUTING.md](CONTRIBUTING.md).

- `src/Domain`: Domain logic (RouterService, Participant model, etc.)
- `src/Application`: Application layer (Action, Controller)
- `config.jsonc`: Configuration file
- `public`: Public directory (index.php, etc.)

## License
This project is provided under the [MIT License](LICENSE).
