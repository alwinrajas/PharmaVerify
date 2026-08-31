; PharmaVerify — Windows installer
;
; Built by build\Build-Release.ps1, which stages the payload this expects and
; passes the version in. Compiling this file on its own will fail unless that
; payload is present, which is deliberate: an installer assembled by hand is one
; nobody can reproduce.
;
; Structure:
;   [Files]    what goes on disk
;   [Run]      PowerShell scripts that do the configuring, in order
;   [Code]     the wizard pages and the decisions taken from them
;
; The configuring lives in the scripts rather than here. They can be run on
; their own to repair an installation without reinstalling, and they are
; readable by an administrator who does not know Pascal.

#ifndef AppVersion
  #define AppVersion "1.0.0"
#endif

#define AppName        "PharmaVerify"
#define AppPublisher   "Vertical Lines Technologies"
#define DefaultPort    "8000"
#define DefaultHost    "pharmaverify.local"

[Setup]
AppId={{8F3A6C21-7B4E-4D9A-B2E5-1C7D9A4F6E80}
AppName={#AppName}
AppVersion={#AppVersion}
AppVerName={#AppName} {#AppVersion}
AppPublisher={#AppPublisher}
VersionInfoVersion={#AppVersion}
VersionInfoDescription=PharmaVerify Stock Verification
DefaultDirName={autopf}\{#AppName}
DefaultGroupName={#AppName}
OutputDir=..\dist
OutputBaseFilename=PharmaVerify-Setup-v{#AppVersion}
; The .ico already exists at installer\assets\pharmaverify.ico; both the
; installer window and the uninstall entry use it instead of the generic
; Inno Setup icon.
SetupIconFile=assets\pharmaverify.ico
UninstallDisplayIcon={app}\pharmaverify.ico

; QuickCompile skips compression entirely, for validating that the script
; still compiles without waiting on a full lzma2/max pass over the payload —
; minutes rather than seconds on a runtime-sized tree. Never used for a
; release build.
#ifdef QuickCompile
  Compression=none
#else
  Compression=lzma2/max
  SolidCompression=yes
#endif

; Firewall rules, services and Program Files all need elevation. Asked for once,
; at the start, rather than failing part way through.
PrivilegesRequired=admin

; The application is 64-bit only, as is the PHP runtime it carries.
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible

WizardStyle=modern
DisableWelcomePage=no
DisableDirPage=no
DisableProgramGroupPage=yes
AllowNoIcons=yes
UninstallDisplayName={#AppName} {#AppVersion}
SetupLogging=yes

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
; The staged payload. Built by Build-Release.ps1 from a clean checkout with the
; tests green; never assembled by hand.
Source: "..\dist\payload\backend\*"; DestDir: "{app}\backend"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "..\dist\payload\app\*";     DestDir: "{app}\backend\public"; Flags: ignoreversion recursesubdirs createallsubdirs

; The PHP runtime, so the pharmacy never installs PHP.
Source: "runtime\php\*"; DestDir: "{app}\runtime\php"; Flags: ignoreversion recursesubdirs createallsubdirs

; The scripts that do the work, kept where an administrator can re-run them.
Source: "scripts\*.ps1"; DestDir: "{app}\scripts"; Flags: ignoreversion

; Used by shortcuts and the uninstall entry (see UninstallDisplayIcon above).
Source: "assets\pharmaverify.ico"; DestDir: "{app}"; Flags: ignoreversion

; The address-routing component's MSI, bundled when the build machine staged
; it (installer\scripts\Get-Redistributables.ps1) so offline pharmacies can
; install without internet access. skipifsourcedoesntexist because a build
; done without staging it must still compile - Install-WebServer.ps1 falls
; back to downloading it.
Source: "runtime\redist\*"; DestDir: "{app}\runtime\redist"; Flags: ignoreversion skipifsourcedoesntexist

[Dirs]
; Mutable data lives outside Program Files: that tree is read-only for standard
; users and is replaced wholesale on upgrade, which would take the logs, the
; configuration and the backups with it.
Name: "{commonappdata}\{#AppName}";          Permissions: users-modify
Name: "{commonappdata}\{#AppName}\logs";     Permissions: users-modify
Name: "{commonappdata}\{#AppName}\backups";  Permissions: users-modify
Name: "{commonappdata}\{#AppName}\config"

[Icons]
Name: "{group}\Open {#AppName}";       Filename: "{code:GetEndpoint}"; IconFilename: "{app}\pharmaverify.ico"
Name: "{group}\{#AppName} Health";     Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -NoExit -File ""{app}\scripts\Test-Health.ps1"""
Name: "{group}\Back Up Now";           Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -NoExit -File ""{app}\scripts\Backup-Database.ps1"""
Name: "{group}\Uninstall {#AppName}";  Filename: "{uninstallexe}"
Name: "{autodesktop}\{#AppName}";      Filename: "{code:GetEndpoint}"; Tasks: desktopicon; IconFilename: "{app}\pharmaverify.ico"

[Tasks]
Name: "desktopicon"; Description: "Create a desktop shortcut"; GroupDescription: "Shortcuts:"

[Run]
; Offered rather than automatic: an administrator who has just watched the
; wizard may want to read the health report first.
Filename: "{code:GetEndpoint}"; \
  Description: "Open PharmaVerify now"; \
  Flags: postinstall shellexec nowait skipifsilent

[UninstallRun]
; Removed before the files go, while the scripts still exist to do it.

; Surgical: only the PharmaVerify-tagged hosts line goes. Unrelated lines the
; user added since installing, and the hosts.pharmaverify.backup file, are
; left alone — see Set-Hostname.ps1 -Remove for why.
Filename: "powershell.exe"; \
  Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\scripts\Set-Hostname.ps1"" -Remove"; \
  Flags: runhidden waituntilterminated; RunOnceId: "RemoveHosts"

Filename: "powershell.exe"; \
  Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\scripts\Install-WebServer.ps1"" -InstallRoot ""{app}"" -Remove"; \
  Flags: runhidden waituntilterminated; RunOnceId: "RemoveSite"

Filename: "powershell.exe"; \
  Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\scripts\Set-Firewall.ps1"" -Remove"; \
  Flags: runhidden waituntilterminated; RunOnceId: "RemoveFirewall"

[Code]
var
  DbPage:      TInputQueryWizardPage;
  NetworkPage: TInputQueryWizardPage;
  ChecksPage:  TOutputMsgMemoWizardPage;
  DetectedIp:  String;

{ ---------------------------------------------------------------- helpers }

function RunAndCapture(const Command: String; var Output: String): Boolean;
{ Runs a PowerShell one-liner and returns its first line of output.
  Used for the few facts the wizard needs from the machine before anything has
  been installed. }
var
  TempFile: String;
  { LoadStringFromFile reads bytes, so it wants an AnsiString. Passing a
    String is a compile-time type mismatch, not a runtime surprise. }
  Content: AnsiString;
  ResultCode: Integer;
begin
  Result := False;
  Output := '';
  TempFile := ExpandConstant('{tmp}\pv-probe.txt');

  if Exec('powershell.exe',
          '-NoProfile -ExecutionPolicy Bypass -Command "' + Command + ' | Out-File -Encoding ASCII ''' + TempFile + '''"',
          '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
  begin
    if LoadStringFromFile(TempFile, Content) then
    begin
      Output := Trim(String(Content));
      Result := Output <> '';
    end;
    DeleteFile(TempFile);
  end;
end;

function DetectLanAddress(): String;
{ The address handhelds will use.

  Asks which interface Windows would route through rather than enumerating
  adapters, because a PC with Hyper-V, WSL or a VPN has several plausible
  private addresses and only one of them is the one the handhelds share. }
var
  Output: String;
begin
  Result := '';
  if RunAndCapture(
       '(Get-NetRoute -DestinationPrefix ''0.0.0.0/0'' | Sort-Object RouteMetric | Select-Object -First 1 | ' +
       'ForEach-Object { Get-NetIPAddress -InterfaceIndex $_.ifIndex -AddressFamily IPv4 } | ' +
       'Where-Object { $_.IPAddress -ne ''127.0.0.1'' } | Select-Object -First 1 -ExpandProperty IPAddress)',
       Output) then
    Result := Output;
end;

function SqlServerPresent(): Boolean;
var
  Output: String;
begin
  Result := RunAndCapture(
    '(Test-Path ''HKLM:\SOFTWARE\Microsoft\Microsoft SQL Server\Instance Names\SQL'')', Output)
    and (CompareText(Output, 'True') = 0);
end;

function IisPresent(): Boolean;
var
  Output: String;
begin
  Result := RunAndCapture(
    '((Get-WindowsOptionalFeature -Online -FeatureName IIS-WebServerRole).State)', Output)
    and (CompareText(Output, 'Enabled') = 0);
end;

function ExistingInstallation(): Boolean;
var
  Value: String;
begin
  Result := RegQueryStringValue(HKLM, 'SOFTWARE\Microsoft\Windows\CurrentVersion\Uninstall\{#emit SetupSetting("AppId")}_is1',
                                'DisplayVersion', Value);
end;

{ --------------------------------------------------------- values for [Run] }
{ Placed ahead of the pages and memos that use them: Pascal Script compiles
  top to bottom with no forward references, and both UpdateReadyMemo and
  CurPageChanged below call these. }

function GetPort(Param: String): String;
begin
  Result := NetworkPage.Values[1];
end;

function GetHostname(Param: String): String;
{ The local hostname is fixed, not user-editable: it is what the loopback
  hosts entry publishes, and it has to match what Set-Hostname.ps1 writes and
  what the handheld app's permitted-hosts list already contains. The Network
  page's one input field is for the address the *handhelds* use, which may be
  a different thing entirely (usually the LAN IP). }
begin
  Result := '{#DefaultHost}';
end;

function GetEndpoint(Param: String): String;
{ The local hostname URL. Shortcuts and the post-install "Open PharmaVerify"
  action use this — the installer's hosts entry makes it resolve on this PC
  even before any router DNS entry for the handhelds exists. }
begin
  Result := 'http://{#DefaultHost}:' + NetworkPage.Values[1];
end;

function GetLanEndpoint(Param: String): String;
{ What the handheld scanners are told to use — whatever address the
  administrator confirmed or typed on the Network page. }
begin
  Result := 'http://' + NetworkPage.Values[0] + ':' + NetworkPage.Values[1];
end;

function GetHhtAddress(Param: String): String;
begin
  Result := NetworkPage.Values[0];
end;


{ ------------------------------------------------------------ wizard pages }

procedure InitializeWizard();
var
  Report: String;
begin
  DetectedIp := DetectLanAddress();

  { What this machine can and cannot do, before anything is written. An
    installation that is going to fail for want of SQL Server should say so
    here, not half way through. }
  Report := 'System check' + #13#10 + #13#10;

  if DetectedIp <> '' then
    Report := Report + '  [ok]   Network found: ' + DetectedIp + #13#10
  else
    Report := Report + '  [!]    No network was found. Handhelds will not be able to reach this PC.' + #13#10;

  if SqlServerPresent() then
    Report := Report + '  [ok]   SQL Server is installed' + #13#10
  else
    Report := Report + '  [!]    SQL Server was not found. Install SQL Server Express first, then run this again.' + #13#10;

  if IisPresent() then
    Report := Report + '  [ok]   Web server components are present' + #13#10
  else
    Report := Report + '  [--]   Web server components will be enabled during installation' + #13#10;

  if ExistingInstallation() then
    Report := Report + #13#10 + 'An existing PharmaVerify installation was found.' + #13#10 +
              'It will be upgraded. Your database and settings are kept.' + #13#10;

  Report := Report + #13#10 +
    'Anything marked [!] needs attention before PharmaVerify will work fully.' + #13#10 +
    'The installation will continue and tell you what remains.';

  ChecksPage := CreateOutputMsgMemoPage(wpWelcome,
    'System check', 'What this PC already has',
    'Setup looked at this computer:', Report);

  DbPage := CreateInputQueryPage(wpSelectDir,
    'Database', 'Where PharmaVerify keeps its records',
    'A database password is needed. It is used only by PharmaVerify and is not something anyone has to remember.');
  DbPage.Add('Database name:', False);
  DbPage.Add('Password:', True);
  DbPage.Add('Confirm password:', True);
  DbPage.Values[0] := 'pharmaverify';

  NetworkPage := CreateInputQueryPage(DbPage.ID,
    'Network', 'How the handheld terminals will reach this PC',
    'This PC will open PharmaVerify at http://{#DefaultHost}:{#DefaultPort} (set up automatically). ' +
    'Handheld scanners are separate devices and must use this PC''s network address.');
  NetworkPage.Add('Address the handheld scanners will use:', False);
  NetworkPage.Add('Port:', False);

  { Defaults to the address Setup already detected, so the common case is just
    confirming it; falls back to the hostname only when no network was found
    to detect an address from. }
  if DetectedIp <> '' then
    NetworkPage.Values[0] := DetectedIp
  else
    NetworkPage.Values[0] := '{#DefaultHost}';
  NetworkPage.Values[1] := '{#DefaultPort}';
end;

function NextButtonClick(CurPageID: Integer): Boolean;
var
  Password: String;
begin
  Result := True;

  if CurPageID = DbPage.ID then
  begin
    Password := DbPage.Values[1];

    { Checked here rather than left to SQL Server. Its policy rejection arrives
      as an error code half way through installation, by which time the wizard
      has been dismissed. }
    if Length(Password) < 12 then
    begin
      MsgBox('Please use a password of at least 12 characters.' + #13#10 + #13#10 +
             'This login can read the whole stock history, so it is worth a strong one. ' +
             'Nobody has to type it again — PharmaVerify stores it.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    if Password <> DbPage.Values[2] then
    begin
      MsgBox('The two passwords do not match.', mbError, MB_OK);
      Result := False;
      Exit;
    end;

    if Trim(DbPage.Values[0]) = '' then
    begin
      MsgBox('Please give the database a name.', mbError, MB_OK);
      Result := False;
    end;
  end;

  if CurPageID = NetworkPage.ID then
  begin
    if Trim(NetworkPage.Values[0]) = '' then
    begin
      MsgBox('Please give the address the handheld scanners should use.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

function UpdateReadyMemo(const Space, NewLine, MemoUserInfoInfo, MemoDirInfo,
  MemoTypeInfo, MemoComponentsInfo, MemoGroupInfo, MemoTasksInfo: String): String;
{ The summary before installing. In the client's terms, not the system's. }
begin
  Result :=
    'Install to:' + NewLine + Space + ExpandConstant('{app}') + NewLine + NewLine +
    'Database:' + NewLine + Space + DbPage.Values[0] + ' on SQL Server' + NewLine + NewLine +
    'On this PC:' + NewLine + Space + GetEndpoint('') + NewLine + NewLine +
    'Handheld scanners:' + NewLine + Space + GetLanEndpoint('') + NewLine + NewLine +
    'Setup will also:' + NewLine +
    Space + 'add pharmaverify.local to this PC automatically (hosts file, backed up first)' + NewLine +
    Space + 'set up the web server' + NewLine +
    Space + 'allow handhelds through the Windows firewall (private networks only)' + NewLine +
    Space + 'start PharmaVerify and check that it works' + NewLine;

  if MemoTasksInfo <> '' then
    Result := Result + NewLine + MemoTasksInfo;
end;

procedure CurPageChanged(CurPageID: Integer);
{ On the last page, the generic "Setup has finished" text is replaced with the
  two addresses the administrator actually needs, so they do not have to go
  digging through the install log to find what was just written down on the
  Ready page a minute earlier. }
begin
  if CurPageID = wpFinished then
  begin
    WizardForm.FinishedLabel.Caption :=
      'PharmaVerify is installed.' + #13#10 + #13#10 +
      'On this PC:  ' + GetEndpoint('') + #13#10 +
      'Handheld scanners:  ' + GetLanEndpoint('') + #13#10 + #13#10 +
      'The hosts entry, firewall rule and web server were configured automatically.';

    { The default label is sized for a couple of lines; this replacement runs
      to five. Doubling the height keeps it from being clipped rather than
      trying to predict the exact pixel count for every font/DPI combination. }
    WizardForm.FinishedLabel.Height := WizardForm.FinishedLabel.Height * 2;
  end;
end;

{ ---------------------------------------------------------- configuration }

{ The configuration used to be a [Run] entry invoking powershell.exe -File with
  a parenthesised ConvertTo-SecureString expression as an argument. Two things
  were wrong with that, and together they produced installations that looked
  complete and had configured nothing:

    1. -File passes arguments as literal text, never as expressions, so the
       password parameter received the string "(ConvertTo-SecureString ..."
       and binding failed before the script logged its first line.
    2. PowerShell returns exit code 0 for -File parameter-binding failures,
       and [Run] does not check exit codes anyway - so the wizard carried on
       to "Installation Complete".

  It now runs from here, where the exit code is read and acted on. The
  password travels through an environment variable scoped to this process and
  its children, blanked immediately after: a command line is readable by every
  process on the machine, an environment variable of one short-lived elevated
  process is not. }

function SetEnvironmentVariable(lpName, lpValue: String): Boolean;
  external 'SetEnvironmentVariableW@kernel32.dll stdcall';

function DescribeConfigFailure(Code: Integer): String;
begin
  case Code of
    1:  Result := 'Administrator rights were not available.';
    2:  Result := 'No usable network connection was found.';
    3:  Result := 'The configuration file could not be written.';
    4:  Result := 'The database could not be set up. Check that SQL Server Express is installed and running.';
    5:  Result := 'The web server could not be configured.';
    6:  Result := 'The chosen port is already in use by another program. The log names it.';
    9:  Result := 'An unexpected internal error occurred. The log has the full details.';
    10: Result := 'Configuration finished, but some health checks need attention.';
  else
    Result := 'An unexpected error occurred (code ' + IntToStr(Code) + ').';
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  Params: String;
  ResultCode: Integer;
  Dummy: Boolean;
begin
  if CurStep <> ssPostInstall then Exit;

  Dummy := SetEnvironmentVariable('PV_DB_PASSWORD', DbPage.Values[1]);
  try
    { -Command, not -File: it propagates the script exit code faithfully via
      the trailing "exit $LASTEXITCODE", which -File does not reliably do. }
    Params :=
      '-NoProfile -ExecutionPolicy Bypass -Command "' +
      '& ' + '''' + ExpandConstant('{app}') + '\scripts\Install-PharmaVerify.ps1' + '''' +
      ' -InstallRoot ' + '''' + ExpandConstant('{app}') + '''' +
      ' -Port ' + NetworkPage.Values[1] +
      ' -Hostname ' + '''' + 'pharmaverify.local' + '''' +
      ' -HhtAddress ' + '''' + GetHhtAddress('') + '''' +
      '; exit $LASTEXITCODE"';

    if not Exec('powershell.exe', Params, '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
      ResultCode := -1;
  finally
    Dummy := SetEnvironmentVariable('PV_DB_PASSWORD', '');
  end;

  if ResultCode = 0 then Exit;

  if ResultCode = 10 then
    MsgBox('PharmaVerify is installed and configured, but some checks need attention.'
           + #13#10 + #13#10 + 'Open Start menu > PharmaVerify > PharmaVerify Health to see exactly what, or read:'
           + #13#10 + ExpandConstant('{commonappdata}\PharmaVerify\logs\install.log'),
           mbInformation, MB_OK)
  else
    MsgBox('PHARMAVERIFY CONFIGURATION DID NOT COMPLETE'
           + #13#10 + #13#10 + DescribeConfigFailure(ResultCode)
           + #13#10 + #13#10 + 'The full log is at:'
           + #13#10 + ExpandConstant('{commonappdata}\PharmaVerify\logs\install.log')
           + #13#10 + #13#10 + 'After fixing the cause, run this as administrator to finish without reinstalling:'
           + #13#10 + ExpandConstant('{app}') + '\scripts\Install-PharmaVerify.ps1',
           mbError, MB_OK);
end;


{ ------------------------------------------------------------- uninstalling }

function InitializeUninstall(): Boolean;
begin
  Result := True;

  { Said plainly, because the opposite assumption is the dangerous one. Someone
    removing the application to reinstall it must not have to wonder whether
    they are about to lose the stock history. }
  MsgBox('PharmaVerify will be removed from this computer.' + #13#10 + #13#10 +
         'Your database is NOT deleted. Stock counts, audits and settings are kept, ' +
         'and a reinstall will find them again.' + #13#10 + #13#10 +
         'Backups and logs are also kept, in:' + #13#10 +
         ExpandConstant('{commonappdata}\{#AppName}'),
         mbInformation, MB_OK);
end;
