using System;
using System.Collections.Generic;
using System.ComponentModel;
using System.Data;
using System.Drawing;
using System.Linq;
using System.Text;
using System.Windows.Forms;

namespace ORK3.View
{
    public delegate bool SaveSettingsHandler(Dictionary<string, string> s);

    public partial class Settings : Form
    {
        public event SaveSettingsHandler SaveSettings = delegate { return false; };
        private Dictionary<string, string> settings;
        private Dictionary<string, string> originals;

        private System.Timers.Timer UITimer;
        private System.Timers.ElapsedEventHandler UIHandler;

        public Settings(Dictionary<string, string> s)
        {
            settings = new Dictionary<string, string>(s);
            originals = new Dictionary<string, string>(settings);
            InitializeComponent();
            SettingsToControls();

            UITimer = new System.Timers.Timer();
            UITimer.Enabled = false;
            UITimer.Interval = 3000;
            UIHandler = new System.Timers.ElapsedEventHandler(UITimer_Elapsed);

            textBoxPassword.TextChanged += new EventHandler(Setting_Changed);
            textBoxUserName.TextChanged+= new EventHandler(Setting_Changed);

            KeyPress += new KeyPressEventHandler(Settings_KeyPress);
        }

        void Settings_KeyPress(object sender, KeyPressEventArgs e)
        {
            if (e.KeyChar == (char)27)
            {
                this.Close();
            }
        }

        void Setting_Changed(object sender, EventArgs e)
        {
            TurnOffUITimer();
            toolStripStatusLabelSettingsStatus.Text = "Settings changed.";
            TurnOnUITimer();
        }

        private void SettingsToControls()
        {
            textBoxUserName.Text = originals["UserName"];
            textBoxPassword.Text = originals["Password"];
        }

        private void buttonSave_Click(object sender, EventArgs e)
        {
            TurnOffUITimer();
            toolStripStatusLabelSettingsStatus.Text = "Saving";
            settings["UserName"] = textBoxUserName.Text;
            settings["Password"] = textBoxPassword.Text;
            if (SaveSettings(settings)) {
                toolStripStatusLabelSettingsStatus.Text = "Saved";
                originals = new Dictionary<string, string>(settings);
            } else {
                toolStripStatusLabelSettingsStatus.Text = "Save Cancelled";
            }
            TurnOnUITimer();
        }

        void UITimer_Elapsed(object sender, System.Timers.ElapsedEventArgs e)
        {
            toolStripStatusLabelSettingsStatus.Text = "";
            TurnOffUITimer();
        }

        private void TurnOnUITimer()
        {
            UITimer.Elapsed += UIHandler;
            UITimer.Enabled = true;
        }

        private void TurnOffUITimer()
        {
            UITimer.Enabled = false;
            UITimer.Elapsed -= UIHandler;
        }

        private void buttonReset_Click(object sender, EventArgs e)
        {
            SettingsToControls();
        }
    }
}
