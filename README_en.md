# participants-router

[日本語](README.md) | **English**
&emsp;&emsp;
[![Tests](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml/badge.svg)](https://github.com/miyamoto-hai-lab/participants-router/actions/workflows/tests.yml)

**participants-router** is a PHP backend routing system designed for psychological experiments and online surveys.
It assigns unique experiment conditions to each participant and manages transitions to multiple experiment steps (such as consent forms, tasks, questionnaires, etc.).

## Features

- **Single URL Distribution**: Simply distribute one common URL (entry point) to all participants, and they will be automatically routed to their assigned condition URLs.
- **Duplicate Participation Prevention**: Manages participation status using browser IDs or similar keys to prevent and control duplicate participation.
- **Flexible Assignment Strategy**: Supports strategies like minimizing participant counts (Minimal Group Assignment) or random assignment.
- **Access Control**: Advanced participation conditions (screening) can be configured using regular expressions or integration with external APIs (e.g., CrowdWorks).
- **Heartbeat Monitoring**: Provides a heartbeat API to detect participant dropouts.
- **Stateful Progress Management**: Manages which step a participant is currently on in the database, allowing them to resume from the correct position upon reload or re-access.

## Requirements

- **Web Server**: Apache, Nginx, etc.
- **PHP**: 8.3 or higher recommended
- **Database**: MySQL, PostgreSQL, SQLite (PDO compatible DB)
- **Composer**: PHP package manager (https://getcomposer.org/)

## Get Started

1. **Clone Repository**
   ```shell
   git clone https://github.com/miyamoto-hai-lab/participants-router.git
   cd participants-router
   ```

2. **Install Dependencies**
   Install dependent packages using [Composer](https://getcomposer.org/).
   ```shell
   composer install
   ```

3. **Edit Configuration File**
   Edit `config.jsonc` according to your environment.
   Configure database connection information and experiment settings.

   When editing with [Visual Studio Code](https://code.visualstudio.com/) or similar editors, descriptions of configuration items will be displayed based on `config.schema.json`.

4. **Deploy to Web Server**
   Place all files, including the `vendor` directory generated in Step 2, in your web server's public directory (document root) or a location accessible from it.

   Necessary tables (default: `participants_routes`) will be automatically created upon the first access to the API.
   If using SQLite, the database file will be automatically created at the specified path if it does not exist.

## Configuration (`config.jsonc`)

The configuration file is written in JSONC (JSON with Comments) format. Here are the main configuration items.

### Basic Settings

```jsonc
{
    "$schema": "./config.schema.json",
    // Base path for API (e.g., "/api/router")
    "base_path": "/api/router",

    // Database connection settings
    "database": {
        "url": "mysql://user:pass@localhost/dbname", // or sqlite://./db.sqlite
        "table": "participants_routes"
    },

    "experiments": {
        // Experiment ID (used in API requests)
        "my_experiment_v1": {
            "enable": true, // Set to false to stop access
            "config": { ... } // Detailed settings per experiment
        }
    }
}
```

### Experiment Configuration (`config`) Details

| Key | Description |
| :--- | :--- |
| `access_control` | Participation condition (screening) rules. Regex and external API integration are possible. |
| `assignment_strategy` | Assignment strategy. `minimum` (assign to condition with fewest participants) or `random`. |
| `fallback_url` | URL to redirect to when full or experiment is disabled. |
| `heartbeat_intervalsec` | Time window (seconds) to count as an valid participant. Participants without a heartbeat within this time may be considered "dropped out" and excluded from the count. |
| `groups` | Definition of experiment conditions (groups). |

#### Access Control Settings

`access_control` is a feature to restrict users who can participate in the experiment. It is defined as a **logical condition tree** combining `all_of` (AND), `any_of` (OR), and `not` (NOT).

**Logic Operators:**

| Key | Description |
| :--- | :--- |
| `all_of` | Returns `true` if **all** conditions in the list are `true` (AND). |
| `any_of` | Returns `true` if **any** condition in the list is `true` (OR). |
| `not` | **Inverts** the truth value of the specified condition (NOT). |

**Rules (Leaf Conditions):**

At the leaves of the logic operators, describe one of the following rules.

**1. Regex Validation (`type: regex`)**
Checks the value of `properties` sent from the client against a regular expression.

```jsonc
{
    "type": "regex",
    "field": "age",      // Property name to check
    "pattern": "^2[0-9]$" // Regex pattern (e.g., 20s)
}
```

**2. External API Query (`type: fetch`)**
Sends an HTTP request to an external server and makes a decision based on the result.
Placeholders in the format `${keyname}` can be used in the URL or Body, which will be replaced by values from `properties` or `browser_id`.

```jsonc
{
    "type": "fetch",
    "url": "https://api.example.com/check?id=${crowdworks_id}",
    "method": "GET", // GET (default) or POST
    // "headers": { "Authorization": "Bearer ..." },
    // "body": { "id": "${crowdworks_id}" }, // For POST
    "expected_status": 200 // HTTP status considered a success (default: 200 OK range)
}
```

**Configuration Example: Complex Condition**

Example: Allow only if "CrowdWorks ID is a 7-digit number" **AND** "External API duplicate check is OK (returns 200 implies duplicate, so invert with `not` to deny)":

```jsonc
"access_control": {
    "condition": {
        "all_of": [
            {
                "type": "regex",
                "field": "crowdworks_id",
                "pattern": "^\\d{7}$"
            },
            {
                "not": { 
                    "type": "fetch",
                    "url": "https://api.example.com/check_duplicate/${crowdworks_id}",
                    "expected_status": 200 // If duplicate exists (200), true -> not makes it false (deny)
                }
            }
        ]
    },
    "action": "allow", // Action when condition is true (currently only allow)
    "deny_redirect": "https://example.com/screened_out.html" // Destination URL if denied
}
```

#### Groups (Conditions & Steps) Settings

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
            "https://task.example.com/task_B", // Different task from group_A
            "https://survey.example.com/post_survey"
        ]
    }
}
```

## API Specification

Clients (e.g., frontend apps for experiments) mainly use the following three APIs. All responses are in JSON format.

### 1. Assign Participation (Assign)

Registers participation in the experiment and retrieves the URL for the first step.

- **Endpoint**: `POST /router/assign`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_client_id_abc123", // ID uniquely identifying the experimental client
  "properties": {
    "crowdworks_id": "1234567", // Attributes used for access_control, etc.
    "age": 25
  }
}
```

> [!TIP]
> **About browser_id**
> `browser_id` needs to be unique among experimental clients and recoverable upon re-access.
> For example, setting a CrowdWorker specific ID is possible, but not recommended as it cannot handle re-access from a different browser.
> Also, IDs that change every time the experiment page is accessed, like session IDs, are not recommended because they cannot detect re-access.
>
> We recommend using the **[participants-id](https://github.com/miyamoto-hai-lab/participants-id)** library developed by the Miyamoto Lab for generating and managing client-side IDs. This library facilitates appropriate persistence to local storage and browser-unique ID generation.

**Response (Success):**
```jsonc
{
  "status": "ok",
  "url": "https://survey.example.com/consent", // URL to transition to
  "message": null
}
```

**Response (Denied/Full/Error):**
```jsonc
{
  "status": "ok", // or "error"
  "url": "https://example.com/screened_out.html", // Redirect destination (if configured)
  "message": "Access denied" // or "Full", etc.
}
```

### 2. Move to Next Step (Next)

Completes the current step and retrieves the URL for the next step. The system determines the progress based on the current URL (`current_url`).

- **Endpoint**: `POST /router/next`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_browser_hash_123",
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
  "url": null, // null if no next step
  "message": "Experiment completed"
}
```

### 3. Heartbeat

Notifies that the participant is continuing the experiment (browser is open). Used in conjunction with `heartbeat_intervalsec` setting to accurately track the number of active participants.

- **Endpoint**: `POST /router/heartbeat`
- **Content-Type**: `application/json`

**Request Body:**
```jsonc
{
  "experiment_id": "my_experiment_v1",
  "browser_id": "unique_browser_hash_123"
}
```

**Response:**
```jsonc
{
  "status": "ok"
}
```

## Client Implementation Example (jsPsych)

This is an implementation example combining [participants-id](https://github.com/miyamoto-hai-lab/participants-id) library and [jsPsych](https://www.jspsych.org/).

### 1. Initial Assignment (Assign)

In the first screen, obtain (generate) `browser_id` and call the `Assign` API to transition to the experiment URL.

```javascript
// Load participants-id library in html header, etc.
// <script src="https://cdn.jsdelivr.net/gh/miyamoto-hai-lab/participants-id@v1.0.0/dist/participants-id.min.js"></script>

const APP_NAME = "my_experiment_v1";

// Example defined as a jsPsych trial
const loading_process_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: `<div class="loader"></div><p>Transitioning to experiment page...</p>`,
    choices: "NO_KEYS",
    on_load: async () => {
        try {
            // 1. Initialize participants-id
            const participant = new ParticipantsIdLib.AsyncParticipant(
                APP_NAME,
                undefined, 
                // ID validation function
                (id) => typeof id === "string" && id.length > 0
            );

            // 2. Get browser_id (Generate on first time, retrieve from LocalStorage on subsequent times)
            const browserId = await participant.get_browser_id();

            // 3. Save attribute information (if necessary)
            // Example: Get ID input in the previous trial
            // const cwid = jsPsych.data.get().last(1).values()[0].response.cwid;
            // await participant.set_attribute("crowdworks_id", cwid);

            // 4. Participation Request to Server (Assign)
            const response = await fetch('/api/router/assign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    browser_id: browserId,
                    properties: {
                        // Send information required for access_control, etc.
                        // crowdworks_id: cwid 
                    }
                })
            });

            if (!response.ok) throw new Error('Network response was not ok');
            const result = await response.json();

            // 5. Redirect to destination URL
            if (result.data.url) {
                window.location.href = result.data.url;
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

### 2. Heartbeat & Page Transition (Heartbeat & Next)

On each page during the experiment, send heartbeats periodically and call `Next` API at the end of the task to proceed to the next step.

```javascript
// Start Heartbeat on page load
const participant = new ParticipantsIdLib.AsyncParticipant(APP_NAME, /* ... */);

document.addEventListener("DOMContentLoaded", async () => {
    const browserId = await participant.get_browser_id();

    // Send heartbeat every 10 seconds
    if (browserId) {
        setInterval(() => {
            fetch("/api/router/heartbeat", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    experiment_id: APP_NAME,
                    browser_id: browserId
                })
            }).catch(e => console.error("Heartbeat error:", e));
        }, 10000);
    }
});

// Process to proceed to next (e.g., jsPsych trial)
const next_step_trial = {
    type: jsPsychHtmlKeyboardResponse,
    stimulus: "Processing...",
    on_load: async () => {
        const browserId = await participant.get_browser_id();
        const currentUrl = window.location.href;

        // Call Next API
        const response = await fetch('/api/router/next', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                experiment_id: APP_NAME,
                browser_id: browserId,
                current_url: currentUrl,
                properties: {
                    // If branching by score, etc.
                    // score: 100
                }
            })
        });

        const result = await response.json();
        if (result.data.url) {
            window.location.href = result.data.url;
        } else {
            alert("Experiment completed. Thank you.");
        }
    }
};
```

## Directory Structure / For Developers

For detailed directory structure and database schema, please refer to [CONTRIBUTING.md](CONTRIBUTING.md).

- `src/Domain`: Domain logic (RouterService, Participant model, etc.)
- `src/Application`: Application layer (Action, Controller)
- `config.jsonc`: Configuration file
- `public`: Public directory (index.php, etc.)

## License

This project is licensed under the [MIT License](LICENSE).
