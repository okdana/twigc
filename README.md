# twigc

**twigc** is a user-friendly command-line utility for rendering (compiling)
[Twig](http://twig.sensiolabs.org/) templates. It's well suited for testing and
for interacting with Twig through shell scripts and other command-line
applications.

## Usage

**twigc** can render Twig templates supplied via either standard input or a file
path.

```
Usage: twigc [options] [<template>]

Options:
  -h, --help               Display this usage help and exit
  -V, --version            Display version information and exit
  --credits                Display dependency information and exit
  --cache <dir>            Enable caching to specified directory
  -d, --dir <dir>          Add specified search directory to loader
  -e, --escape <strategy>  Specify default auto-escaping strategy
  -E, --env                Derive input data from environment
  -j, --json <dict/file>   Derive input data from specified JSON file or
                           dictionary string
  -p, --pair <input>       Derive input data from specified key=value pair
  --query <input>          Derive input data from specified URL query string
  -s, --strict             Throw exception when undefined variable is referenced
```

### Passing input data

Input data can be passed to the template using a simple key=value syntax with
`-p`:

```
% twigc -p 'name=dana' <<< 'Hello, {{ name }}!'
Hello, dana!
```

Of course, only basic string values can be provided this way. For more complex
data, you can use the JSON option `-j`:

```
% twigc -j '{"numbers": [1, 2, 3]}' <<< '{{ numbers|join("... ") }}!'
1... 2... 3!
```

JSON data can also be provided by file path or on standard input:

```
# JSON from file, template from standard input
% cat numbers.json
{"numbers": [1, 2, 3]}
% twigc -j numbers.json <<< '{{ numbers|join("... ") }}!'
1... 2... 3!

# JSON from standard input, template from file
% cat numbers.twig
{{ numbers|join("... ") }}!
% twigc -j - numbers.twig <<< '{"numbers": [1, 2, 3]}'
1... 2... 3!
```

(**twigc** determines whether the argument to `-j` is a dictionary string or a
file name based on whether the first character is a `{`; if you have a file name
that looks like that, use the absolute path or put `./` in front of it — for
example, `twigc -j './{myfile}.json'`.)

If
[`variables_order`](http://php.net/manual/en/ini.core.php#ini.variables-order)
is configured appropriately in your `php.ini`, you can use the `-E` option to
inherit input data from the environment:

```
% NAME=dana twigc -E <<< 'Hello, {{ NAME }}!'
Hello, dana!
```

(If you *don't* have `E` in `variables_order`, you'll get an error.)

Lastly, there's a `--query` option in case you want to pass input as a URL query
string:

```
% twigc --query '?foo=&name=dana&bar=' <<< 'Hello, {{ name }}!'
Hello, dana!
```

All of the aforementioned input options can be given multiple times and in any
combination, but the values associated with each input type override other
values with the same name using the following order of precedence (from lowest
to highest): environment, query, JSON, pair. In other words:

```
# Pair has higher precedence than environment
% name=foo twigc -p name=bar -E <<< '{{ name }} wins'
bar wins

# JSON has higher precedence than query
% twigc -j '{"name": "foo"}' --query name=bar <<< '{{ name }} wins'
foo wins

# Inputs of the same type have equal precedence and are taken in the order given
% twigc -j '{"name": "foo"}' -p name=bar -p name=baz <<< '{{ name }} wins'
baz wins
```

### Configuring auto-escaping

Normally, input data is [auto-escaped](http://twig.sensiolabs.org/doc/api.html)
during rendering based on the template file extension (or disabled by default if
using standard input), but this is configurable:

```
# No auto-escaping by default on standard input
% twigc -p 'html=<p>Hello!</p>' <<< '{{ html }}'
<p>Hello!</p>

# Explicit HTML auto-escaping
% twigc -e html -p 'html=<p>Hello!</p>' <<< '{{ html }}'
&lt;p&gt;Hello!&lt;/p&gt;

# Explicit JavaScript auto-escaping
% twigc -e js -p 'html=<p>Hello!</p>' <<< '{{ html }}'
\x3Cp\x3EHello\x21\x3C\x2Fp\x3E

# Explicit URL auto-escaping
% twigc -e url -p 'html=<p>Hello!</p>' <<< '{{ html }}'
%3Cp%3EHello%21%3C%2Fp%3E
```

Of course, you can always control escaping from within the template using the
[`escape`](https://twig.symfony.com/doc/2.x/filters/escape.html) filter.

The following auto-escape methods are available:

* **`none`** (aka **`false`**, **`no`**, **`never`**, &c.) —
  No escaping is performed; the input is rendered as-is. This is the default for
  templates taken from standard input and for files with unrecognised
  extensions.

* **`html`** (aka **`true`**, **`yes`**, **`always`**, &c.) —
  Ampersand-escaping as suitable for inclusion in an HTML body. This is the most
  common escaping method used for rendering Web pages with Twig, and the default
  method used by the `escape` filter.

* **`css`** —
  Hex-escaping as suitable for inclusion in a CSS value or identifier.

* **`html_attr`** —
  Ampersand-escaping as suitable for inclusion in an HTML attribute value. This
  is similar to the `html` method, but more characters are escaped.

* **`js`** —
  Hex-escaping as suitable for inclusion in a JavaScript string or identifier.

* **`json`** —
  Serialisation according to JSON rules. Strings are quoted and escaped,
  integers are left bare, &c. JSON escaping can often be used to produce strings
  for config files and even languages like C (though incompatibilities do exist
  — be careful).

* **`sh`** —
  Double-quoting and meta-character escaping according to shell rules. This
  method uses double-quoted strings rather than the single-quote method used by
  e.g. `escapeshellarg()` because it is more compatible with software that
  supports only a sub-set of the shell's string syntax (such as 'dotenv'
  libraries).

* **`url`** —
  Percent-escaping as suitable for inclusion in a URL path segment or query
  parameter.

### Enabling strict mode

By default, references in the template to undefined variables are silently
ignored, but you can make Twig throw an exception with the `-s` option:

```
% twigc <<< 'Hello, {{ name }}!'
Hello, !

% twigc -s <<< 'Hello, {{ name }}!'
twigc: Variable "name" does not exist in "-" at line 1.
```

Use of this option is recommended for reliability in scripting scenarios.

### Specifying search directories

If a template file name was provided, the file's parent directory is
automatically added as an include search path; if standard input was used, no
search path is set at all by default. In either case, one or more additional
search paths can be explicitly supplied on the command line:

```
% cat include.twig
Hello!

% twigc <<< '{% include "include.twig" %}'
twigc: Template "include.twig" is not defined in "-" at line 1.

% twigc -d '.' <<< '{% include "include.twig" %}'
Hello!
```

## Installation

**twigc** is provided as a self-contained executable archive; to download it,
see the [releases](https://github.com/okdana/twigc/releases) page.

Of course, you can also build it from source:

```
% git clone https://github.com/okdana/twigc
% cd twigc
% make
```

## Requirements

The **twigc** executable archive is bundled with Twig and all of its other
dependencies; the only thing you need to run it is PHP version 7.0 or higher.

## Limitations, todos, requests

Earlier versions of this tool were implemented in the manner of a Symfony
Console application. I like Symfony components a lot, but the way Console
handles arguments in particular leaves a great deal to be desired if you're
trying to create something that works like a traditional UNIX CLI tool — the API
is confused, the functionality is limited, and worst of all it's buggy. I ended
up switching out the Console application/command/input components for a lazier
and uglier, but ultimately better-behaved, design incorporating the GetOpt.php
library.

If you have any ideas as to how to improve on this situation, please let me
know.

I'd also like to achieve the following goals at some point:

* Add to Packagist
* Improve unit/integration tests
* Create man page
* Create zsh completion function

In the longer term, if i get really bored, maybe i'll add more input and escape
methods. One thing that occurred to me is a custom escape option that takes an
arbitrary escape character and a mask of characters to be escaped with it. For
example, `-e 'custom:%:%'` might escape `printf(3)` format strings. idk

Anyway, if you find a bug or have a request, please let me know.

## Licence and acknowledgements

**twigc** itself is available under the MIT licence. For information about the licences
of its dependencies, run `twigc --credits`.

The `\Dana\Twigc\PharCompiler` class used to build the executable archive is
based on
[`\Composer\Compiler`](https://github.com/composer/composer/blob/master/src/Composer/Compiler.php).

## See also

* [twigphp/Twig](https://github.com/twigphp/Twig) —
  The Twig project on GitHub.

* [farazdagi/twig-cli](https://github.com/farazdagi/twig-cli) —
  Another project that aims to bring Twig to the command line. It's actually
  quite similar in design (though no code is shared); it just didn't have the
  features i wanted and isn't actively developed.

* [twigjs/twig.js](https://github.com/twigjs/twig.js) —
  A pure JavaScript implementation of Twig. It comes with its own command-line
  tool, `twigjs`, which can be used to render Twig templates, but it's quite
  limited.

* [indigojs/twig-cli](https://github.com/indigojs/twig-cli) —
  Another command-line Twig renderer based on Twig.js. Its functionality is very
  similar to (almost exactly the same as?) `twigjs`.

* [mattrobenolt/jinja2-cli](https://github.com/mattrobenolt/jinja2-cli) —
  A command-line [Jinja2](http://jinja.pocoo.org/) renderer. Very comparable to
  **twigc** in terms of features.

