# twigc

**twigc** is a user-friendly command-line utility for rendering (compiling)
[Twig](http://twig.sensiolabs.org/) templates. It's well suited for testing and
for interacting with Twig through shell scripts and other command-line
applications.

## Usage overview

```
Usage:
  twigc [options] [--] [<template>]

Arguments:
  template             Twig template file to render (use `-` for STDIN)

Options:
  -h, --help           Display this usage help
  -V, --version        Display version information
      --credits        Display dependency credits (including Twig version)
  -d, --dir=DIR        Add search directory to loader (multiple values allowed)
      --env            Treat environment variables as input data
  -e, --escape=ESCAPE  Set autoescape environment option
  -j, --json=JSON      Pass variables as JSON (dictionary string or file path)
  -p, --pair=PAIR      Pass variable as key=value pair (multiple values allowed)
      --query=QUERY    Pass variables as URL query string
  -s, --strict         Enable strict_variables environment option
```

**twigc** can render Twig templates supplied via either standard input or a file
path. Input data can be passed to the template using a simple key=value syntax:

```
% twigc -p 'name=dana' <<< 'Hello, {{ name }}!'
Hello, dana!
```

Of course, only simple string values can be provided this way. For more complex
data, you can use the JSON option:

```
% twigc -j '{ "numbers": [1, 2, 3] }' <<< '{{ numbers|join("... ") }}!'
1... 2... 3!
```

JSON data can also be provided by file path or on standard input:

```
% cat numbers.json
{ "numbers": [1, 2, 3] }
% twigc -j numbers.json <<< '{{ numbers|join("... ") }}!'
1... 2... 3!

% cat numbers.twig
{{ numbers|join("... ") }}!
% twigc -j - numbers.twig <<< '{ "numbers": [1, 2, 3] }'
```

Normally, input data is [auto-escaped](http://twig.sensiolabs.org/doc/api.html)
based on the template file extension (or disabled by default if using standard
input), but this is configurable:

```
% twigc -p 'html=<p>Hello!</p>' <<< '{{ html }}'
<p>Hello!</p>

% twigc -p 'html=<p>Hello!</p>' -e 'html' <<< '{{ html }}'
&lt;p&gt;Hello!&lt;/p&gt;

% twigc -p 'html=<p>Hello!</p>' -e 'js' <<< '{{ html }}'
\x3Cp\x3EHello\x21\x3C\x2Fp\x3E

% twigc -p 'html=<p>Hello!</p>' -e 'url' <<< '{{ html }}'
%3Cp%3EHello%21%3C%2Fp%3E
```

By default, references in the template to undefined variables are silently
ignored; you can make Twig return an error instead:

```
% twigc <<< 'Hello, {{ name }}!'
Hello, !

% twigc -s <<< 'Hello, {{ name }}!'
[Twig_Error_Runtime]
Variable "name" does not exist in "-" at line 1
```

If a template file name was provided, the file's parent directory is used for
Twig's include search path; if standard input was used, no search path is set at
all by default. In either case, one or more additional search paths can be
explicitly supplied on the command line:

```
% cat include.twig
Hello!

% twigc <<< '{% include "include.twig" %}'
[Twig_Error_Loader]
Template "include.twig" is not defined in "-" at line 1.

% twigc -d '.' <<< '{% include "include.twig" %}'
Hello!
```

## Installation

**twigc** is provided as a self-contained executable archive; to download it,
see the [releases](https://github.com/okdana/twigc/releases) page.

Of course, you can also build and install it from source:

```
% git clone https://github.com/okdana/twigc
% cd twigc
% make
```

## Requirements

The **twigc** executable is bundled with Twig and all of its other dependencies;
the only thing you need to run it is PHP version 5.5 or higher.

## Licence and acknowledgements

**twigc** is available under the MIT licence. For information about the licences
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
  features i wanted.
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

