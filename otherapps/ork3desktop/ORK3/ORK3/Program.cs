using System;
using System.Collections.Generic;
using System.Linq;
using System.Windows.Forms;

namespace ORK3
{
    static class Program
    {
        /// <summary>
        /// The main entry point for the application.
        /// </summary>
        [STAThread]
        static void Main()
        {
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            Model.Configuration.Config.Initialize();
            View.MainForm M = new View.MainForm();
            Controller.Controller C = new Controller.Controller();

            M.OpenSettingsForm += new View.OpenSettingsFormHandler(C.OpenSettingsForm);

            Application.Run(M);
        }
    }
}
