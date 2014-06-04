using System;
using System.Collections.Generic;
using System.Linq;
using System.Text;

namespace ORK3.Controller
{
    public partial class Controller
    {
        public void OpenSettingsForm()
        {
            View.Settings S = new View.Settings(Model.Configuration.Config.GetConfig);

            S.SaveSettings += new View.SaveSettingsHandler(SaveSettings);

            S.Show();
        }

        private bool SaveSettings(Dictionary<string,string> NewSettings)
        {
            try
            {
                Login(NewSettings["UserName"], NewSettings["Password"]);
            }
            catch
            {
                System.Windows.Forms.DialogResult result = System.Windows.Forms.MessageBox.Show(
                    "The User Name and Password specified do not appear to work.  Do you want to save these?",
                    "Log on failure",
                    System.Windows.Forms.MessageBoxButtons.YesNo,
                    System.Windows.Forms.MessageBoxIcon.Warning,
                    System.Windows.Forms.MessageBoxDefaultButton.Button2);
                if (result == System.Windows.Forms.DialogResult.No) return false;
            }
            foreach (KeyValuePair<string, string> config in NewSettings)
            {
                Model.Configuration.Config[config.Key] = config.Value;
            }
            Model.Configuration.Config.WriteConfig();
            return true;
        }

    }
}